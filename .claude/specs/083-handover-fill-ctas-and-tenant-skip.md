# 083 — Handover protocol: admin skip of the tenant side + fill-CTA polish

**Status:** ready
**Type:** feature + UX
**Scope:** medium (~12 files: entity + migration, command + handler, controller, 6 templates, repository untouched, tests)
**Depends on:** none (coordinates with spec 080 — both edit `templates/admin/handover/view.html.twig` and `templates/portal/landlord/handover/view.html.twig`; implement in numeric order to avoid conflicts)

## Problem

The handover protocol needs both sides (tenant + landlord/admin) before it completes — and completion is what releases the storage and applies the new lock code (`ReleaseStorageOnHandoverCompletedHandler`). When a customer ignores the protocol (moved out, unreachable, hostile), the protocol is stuck forever: today's only workaround is the admin opening "Vyplnit za nájemce" and submitting the tenant form *as if they were the tenant* — the timeline then lies ("Nájemce vyplnil") and the mandatory comment forces the admin to invent tenant statements. There is no honest way to waive the tenant side. Separately, the operator wants the fill actions to be reachable from every place an open protocol surfaces.

## Goal

The admin view (`/portal/admin/predavaci-protokol/{id}`) gains a one-click "Přeskočit stranu nájemce" action: it records who skipped and when, the protocol then completes on the landlord side alone (immediately, if that side is already filled — firing the normal `HandoverCompleted` flow), tenant reminders stop, and every surface (timeline, tenant sections, tenant-facing pages) honestly shows "Přeskočeno" instead of pretending the tenant answered. Fill CTAs are verified/added so an admin can jump straight to the landlord fill form from the operations hub and the admin protocol view header.

## Context (current state)

- **Entity**: `src/Entity/HandoverProtocol.php` — `completeTenantSide()` (`:67-81`), `completeLandlordSide()` (`:83-98`; completes the protocol only `if (null !== $this->tenantCompletedAt)` at `:93`), `needsTenantCompletion()` (`:105-108`), private `markCompleted()` (`:157-168`, records `HandoverCompleted` → storage release + lock-code apply + completed e-mail). `HandoverStatus` enum (`src/Enum/HandoverStatus.php`): PENDING / TENANT_COMPLETED ("Čeká na pronajímatele") / LANDLORD_COMPLETED / COMPLETED; `isWaitingOn(actor)`, `badgeClass()`.
- **Fill CTAs that already exist and work** (verified — do NOT rebuild):
  - Admin view `templates/admin/handover/view.html.twig` has "Vyplnit za nájemce" → `portal_user_handover_view` (`:127-129`) and "Vyplnit za pronajímatele" → `portal_landlord_handover_view` (`:168-170`). Both work for admins: `HandoverProtocolVoter` short-circuits admins to `true` (`src/Service/Security/HandoverProtocolVoter.php:40`) and `ROLE_ADMIN` inherits `ROLE_LANDLORD` (`config/packages/security.php:63-65`).
  - Order-detail banners (`templates/components/handover_banner.html.twig`) deep-link correctly: landlord detail passes `portal_landlord_handover_view` (fill form, `templates/portal/landlord/order/detail.html.twig:70-73`), admin detail passes `admin_handover_view` (`templates/admin/order/detail.html.twig:88-91`).
- **Missing CTA**: operations hub rows (`templates/admin/operations/list.html.twig:80-103`, iterates real `HandoverProtocol` entities) link only "Zobrazit protokol" — no direct jump to the fill form.
- **Reminder machinery — skip integrates for free**: cron `src/Console/ProcessHandoverProtocolsCommand.php` selects via `findIncompleteForReminders` (`status != completed`), then `SendHandoverReminderToTenantHandler.php:30` early-returns unless `$protocol->needsTenantCompletion()` (landlord variant symmetric). Making `needsTenantCompletion()` skip-aware silences tenant reminders with zero cron changes.
- **Tenant-facing views** that need a skipped state: `templates/public/handover_view.html.twig` (signed link; `:121` fallback "Čeká se na vyplnění Vaší části…" would be wrong after a skip) and `templates/portal/user/handover/view.html.twig` (`:127` same text). Both gate the form on `canComplete` → `needsTenantCompletion()`-based, so the form disappears automatically; only the fallback copy needs the new branch. Landlord fill view has its own tenant-part fallback (`templates/portal/landlord/handover/view.html.twig:56-60` "Nájemce zatím svou část nevyplnil.").
- **Audit pattern**: `CompleteLandlordHandoverHandler.php:37-44` (`entityType: 'handover'`, `orderId`, `userIdContext`).
- **Modal conventions**: `components/_danger_modal.html.twig` is password-gated by design (danger zone). Skip is significant but neither destructive nor financial — use a plain `onclick="return confirm(...)"` like the fine-issue button (`templates/admin/fine/create.html.twig:76`).
- **Gotcha**: catch dispatch exceptions via `App\Service\Messenger\HandlerFailureUnwrap::unwrap()` — typed catches never match wrapped exceptions (MESSENGER.md).
- **Fixtures/tests**: `fixtures/HandoverProtocolFixtures.php` (`REF_HANDOVER_PENDING`, `REF_HANDOVER_TENANT_COMPLETED`, `REF_HANDOVER_OVERDUE`); `tests/Integration/Controller/Admin/AdminHandoverViewControllerTest.php`, `tests/Unit/Enum/HandoverStatusTest.php`.

## Requirements

### 1. `src/Entity/HandoverProtocol.php` — skip state

New columns (after `tenantCompletedAt` block):

```php
#[ORM\Column(nullable: true)]
public private(set) ?\DateTimeImmutable $tenantSkippedAt = null;

#[ORM\ManyToOne(targetEntity: User::class)]
#[ORM\JoinColumn(nullable: true)]
public private(set) ?User $tenantSkippedBy = null;
```

New method (mirror the guard style of `completeTenantSide`):

```php
public function skipTenantSide(User $skippedBy, \DateTimeImmutable $now): void
{
    if (null !== $this->tenantCompletedAt) {
        throw new \DomainException('Nájemce již předávací protokol vyplnil.');
    }
    if (null !== $this->tenantSkippedAt) {
        throw new \DomainException('Strana nájemce již byla přeskočena.');
    }

    $this->tenantSkippedAt = $now;
    $this->tenantSkippedBy = $skippedBy;

    if (null !== $this->landlordCompletedAt) {
        $this->markCompleted($now);   // fires HandoverCompleted → release + lock code + e-mail
    } else {
        $this->status = HandoverStatus::TENANT_COMPLETED;   // label "Čeká na pronajímatele" stays accurate
    }
}
```

Three touch-ups so the rest of the system treats skipped as settled:
- `needsTenantCompletion()` → `return null === $this->tenantCompletedAt && null === $this->tenantSkippedAt;` (silences tenant reminders via the existing handler gate; hides tenant forms via existing `canComplete` logic).
- `completeLandlordSide()` `:93` → `if (null !== $this->tenantCompletedAt || null !== $this->tenantSkippedAt) { $this->markCompleted($now); }`.
- `completeTenantSide()` — add after the existing guard: `if (null !== $this->tenantSkippedAt) { throw new \DomainException('Strana nájemce byla přeskočena administrátorem.'); }`.

No `HandoverStatus` enum change — TENANT_COMPLETED already means "waiting on landlord", which is exactly the skipped-but-open state; display honesty comes from `tenantSkippedAt` checks in templates. Migration via `make:migration`.

### 2. `src/Command/SkipTenantHandoverCommand.php` + handler

```php
final readonly class SkipTenantHandoverCommand
{
    public function __construct(
        public Uuid $handoverProtocolId,
        public Uuid $skippedById,
    ) {}
}
```

`SkipTenantHandoverHandler` (mirror `CompleteLandlordHandoverHandler` shape): load protocol via `HandoverProtocolRepository::get()`, load the admin via `EntityManager::find(User::class, …)`, call `skipTenantSide()`, audit-log:

```php
$this->auditLogger->log(
    entityType: 'handover',
    entityId: $protocol->id->toRfc4122(),
    eventType: 'tenant_skipped',
    payload: ['status' => $protocol->status->value, 'skipped_by' => $command->skippedById->toRfc4122()],
    orderId: $protocol->contract->order->id,
    userIdContext: $protocol->contract->user->id,
);
```

### 3. `src/Controller/Admin/AdminHandoverSkipTenantController.php`

`/portal/admin/predavaci-protokol/{id}/preskocit-najemce`, name `admin_handover_skip_tenant`, `methods: ['POST']`, `requirements: ['id' => '[0-9a-f-]{36}']`, `#[IsGranted('ROLE_ADMIN')]`. Load protocol, `denyAccessUnlessGranted(HandoverProtocolVoter::VIEW, …)`, dispatch with `#[CurrentUser]` admin's id; catch `\Throwable` → `HandlerFailureUnwrap::unwrap()` → if `\DomainException`, error flash with its message, else rethrow. Success flash: `'Strana nájemce byla přeskočena.'` (append `' Protokol je tím dokončen.'` when `$protocol->isFullyCompleted()` after dispatch). Redirect to `admin_handover_view`.

**Admin-only by design** — landlords cannot waive the counterparty side of a two-party document; the voter's admin short-circuit plus the route guard enforce it.

### 4. `templates/admin/handover/view.html.twig` — skip UI + header CTA

- **Header** (`:14-22`): next to the status badge, when `protocol.needsLandlordCompletion` add a primary `btn btn-primary` "Vyplnit protokol" → `path('portal_landlord_handover_view', {id: protocol.id})` (the most common admin action, one click from anywhere that lands here).
- **Tenant section** (`:99-132`): three states now —
  1. completed → unchanged;
  2. skipped (`protocol.tenantSkippedAt`) → gray note: `Strana nájemce byla přeskočena administrátorem {{ protocol.tenantSkippedBy.fullName }} dne {{ protocol.tenantSkippedAt|date('d.m.Y H:i') }}. Protokol nevyžaduje vyjádření nájemce.`;
  3. pending → keep both existing buttons and add, beside "Vyplnit za nájemce":

```twig
<form method="post" action="{{ path('admin_handover_skip_tenant', {id: protocol.id}) }}" class="inline">
    <button type="submit" class="btn btn-secondary btn-sm"
            onclick="return confirm('Opravdu přeskočit stranu nájemce? Protokol bude dokončen bez jeho vyjádření. Tuto akci nelze vzít zpět.');">
        Přeskočit stranu nájemce
    </button>
</form>
<p class="mt-2 text-xs text-gray-500">Použijte, když je zákazník nedostupný — protokol se dokončí pouze se stranou pronajímatele.</p>
```

- **Timeline** "Nájemce vyplnil" node (`:60-71`): third branch — skipped → amber-gray dot + `Přeskočeno {{ protocol.tenantSkippedAt|date('d.m.Y H:i') }}`.

### 5. Skipped-state copy on the other three templates

- `templates/portal/landlord/handover/view.html.twig:56-60`: tenant-part fallback gains a skipped branch — gray box `Strana nájemce byla přeskočena administrátorem — vyplňte pouze svou část.`
- `templates/public/handover_view.html.twig:121` and `templates/portal/user/handover/view.html.twig:127`: when `protocol.tenantSkippedAt` show instead: `Vyplnění vaší strany protokolu nebylo vyžadováno.` (neutral gray, not yellow "waiting").
- `handover_banner.html.twig` — **no change**: skip transitions status to TENANT_COMPLETED/COMPLETED, and the banner is status-driven, so texts stay correct automatically.

### 6. `templates/admin/operations/list.html.twig` — direct fill link

In the Akce cell (`:100-102`), before "Zobrazit protokol":

```twig
{% if protocol.needsLandlordCompletion %}
    <a href="{{ path('portal_landlord_handover_view', {id: protocol.id}) }}" class="link text-sm font-semibold">Vyplnit</a>
{% endif %}
```

(The loop iterates real `HandoverProtocol` entities — `needsLandlordCompletion` is available.)

### 7. Tests

- **Unit** (extend entity coverage, e.g. new `tests/Unit/Entity/HandoverProtocolTest.php` if none exists):
  - skip on PENDING → status TENANT_COMPLETED, `needsTenantCompletion()` false, no `HandoverCompleted` event;
  - skip when landlord already completed → status COMPLETED + `completedAt` set + `HandoverCompleted` in `popEvents()`;
  - landlord completing AFTER a skip → COMPLETED + event (the `:93` condition fix);
  - skip after tenant completed / double skip / tenant completing after skip → `\DomainException`.
- **Integration** `AdminHandoverViewControllerTest` + new controller test:
  - admin POST skip on `REF_HANDOVER_PENDING` → redirect, flash, `tenantSkippedAt` + `tenantSkippedBy` persisted, audit row `handover/tenant_skipped`;
  - landlord POST → 403 (route is admin-only);
  - skip on already-tenant-completed protocol → error flash, nothing changed;
  - admin view renders "Přeskočit stranu nájemce" for pending, the skipped note after skip, and the header "Vyplnit protokol" CTA while landlord side is open;
  - operations hub shows "Vyplnit" link for a protocol waiting on landlord.
  - public signed tenant view after skip → shows "nebylo vyžadováno", no form.

## Acceptance

- [ ] On `/portal/admin/predavaci-protokol/{id}` with a pending tenant side, admin clicks "Přeskočit stranu nájemce" (confirm dialog) → skip is recorded with actor + timestamp; if the landlord side was already filled the protocol completes immediately (storage release + lock code + completed e-mail fire exactly once via the normal `HandoverCompleted` flow).
- [ ] After a skip, completing the landlord side completes the protocol; the tenant reminder cron sends nothing for that protocol; tenant-facing pages show "Vyplnění vaší strany protokolu nebylo vyžadováno." and no form; the admin timeline shows "Přeskočeno".
- [ ] Tenant form submission after a skip is rejected (`DomainException`), and skip after tenant completion is rejected.
- [ ] Header "Vyplnit protokol" CTA on the admin view and "Vyplnit" link on operations-hub rows deep-link to the landlord fill form; existing "Vyplnit za nájemce / za pronajímatele" buttons keep working.
- [ ] Migration generated via `make:migration`; `doctrine:schema:validate` clean.
- [ ] `composer quality` green; full `composer test` green (controller/template changes).

## Out of scope

- Un-skip / revert action — the confirm dialog states irreversibility; if truly needed the tenant side is factually blank and a dev can null the two columns. Add only if it comes up in practice.
- Landlord skip rights — waiving the counterparty's side of a two-party record is an operator (admin) decision.
- New `HandoverStatus` case for skipped — TENANT_COMPLETED already models "waiting on landlord only"; a new case would ripple through `isWaitingOn`/`badgeClass`/status maps in 6+ templates for zero behavioral gain.
- Skipping the LANDLORD side — the landlord section carries the new lock code and condition record needed for re-letting; there is no scenario where it can be waived.
- Notifying the tenant that their side was skipped — the completed-protocol e-mail (existing `SendHandoverCompletedEmailHandler`) already tells them the outcome.
- Password gate on skip — reserved for destructive danger-zone actions; a confirm dialog matches the severity.

## Open questions

None — proceed.
