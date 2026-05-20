# 046 — Handover protocol visibility & admin operations hub

**Status:** ready
**Type:** feature
**Scope:** large (~22 files: 3 new controllers, 1 new query/service, 1 new admin view template, 5 templates touched, 1 partial extended, 1 Twig extension, sidebar nav entry, repository extensions, fixtures, tests)
**Depends on:** spec 023 (overdue dashboard pattern, mirrored verbatim), spec 041 (handover signed-URL infrastructure, reused unchanged)

## Problem

The handover protocol (`HandoverProtocol`) is created by a daily cron 7 days before each contract ends or 7 days after `terminatesAt`, and the only way anyone finds out about it is the e-mail (`SendHandoverRequestToTenantHandler` / `SendHandoverRequestToLandlordHandler`). After that:

1. **Customers** have no in-product entry point — `templates/portal/user/order/detail.html.twig` and `templates/public/order_status.html.twig` contain zero handover references. If the e-mail is buried, the protocol sits unfilled until the +3-day reminder cron retries the same e-mail. Logged-in customers can't find it from their order detail; passwordless customers can't find it from `/stav` either.
2. **Landlords** have the same blind spot on `templates/portal/landlord/order/detail.html.twig` — the protocol exists but nothing on the order detail links to `/portal/pronajimatel/predavaci-protokol/{id}`.
3. **Admins** are completely cut out. There is no admin handover route, no admin handover voter branch beyond the `ROLE_ADMIN`-can-do-anything shortcut, no banner on `templates/admin/order/detail.html.twig`, and crucially **no dashboard surface** to spot "this protocol has been sitting for 9 days with no tenant action."

Operationally this means a stuck handover only surfaces when the +14-day force-release fires (and the storage gets booted), or when the customer phones in confused. There's no "this is the operator's worry list" page — the only daily-ops surface admins have today is `/portal/admin/po-splatnosti` (spec 023), and that's payment-only.

## Goal

1. Every actor (customer / landlord / admin) sees the handover protocol from the order detail page, with **status-aware** copy: "Čeká na vyplnění Vámi" / "Čeká na druhou stranu" / "Vyplněno dd.mm.yyyy". Single click reaches the right form / read-only view for that actor. Passwordless customers reach a freshly-signed tenant view from the existing `/objednavka/{id}/stav` permalink without needing the original e-mail.
2. Admins gain a new read-only handover view (`/portal/admin/predavaci-protokol/{id}`) that displays both sides' content + photos + completion timestamps, accessible from the admin order detail banner.
3. Admins gain a single **Operations hub** (`/portal/admin/operace`) — one page that bundles every operator-pending signal: Po splatnosti (link out), Handover sections, Smlouvy končící bez protokolu, Onboarding podepsaný bez platby, Externí předplatné brzy končící. A new sidebar entry "Operace" with a total-count badge lives next to "Po splatnosti"; the existing "Po splatnosti" entry stays unchanged.
4. Once the handover is `COMPLETED`, the order documents panel (portal + public `/stav` + landlord/admin detail) gains a "Předávací protokol" row that links to the read-only view (admin and landlord see the full read-only page; customer sees the same `/portal/predavaci-protokol/{id}` page they always could, now in read-only mode because `needsTenantCompletion()` is false). No PDF export in this spec — link-only archival.

The design priority is **clarity over confusion**: never show the same banner twice on one page, never funnel the customer to two different URLs for the same protocol, and let the operator land on `/portal/admin/operace` and immediately see what needs attention today.

## Context (current state)

**Handover domain**

- `src/Entity/HandoverProtocol.php`: 4-state enum (`PENDING` / `TENANT_COMPLETED` / `LANDLORD_COMPLETED` / `COMPLETED`), 1:1 with `Contract`. Helpers: `needsTenantCompletion()` (line 105), `needsLandlordCompletion()` (line 110), `isFullyCompleted()` (line 100). Holds `createdAt`, `tenantCompletedAt`, `landlordCompletedAt`, `completedAt`, `remindersSentCount`, `lastReminderSentAt`, photos collection partitioned by `uploadedBy` ('tenant' | 'landlord').
- `src/Enum/HandoverStatus.php`: lacks Czech labels and lacks `actor side`-aware helpers. No `label()` method.
- `src/Repository/HandoverProtocolRepository.php`: has `findByContract()`, `findIncompleteForReminders()`, `findExpiredForForceRelease()`. Lacks an admin-facing `findPending()` / `countByBucket()`.
- `src/Console/ProcessHandoverProtocolsCommand.php`: creates protocols at contract end − 7 d (`DAYS_BEFORE_END = 7`), reminds every 3 days, force-releases after 14 days past termination.
- `src/Service/Handover/HandoverUrlGenerator.php`: mints HMAC-signed `public_handover_view` URLs (single method `generateTenantView()`). Reused unchanged for the `/stav` → handover handoff.
- `src/Service/Security/HandoverProtocolVoter.php`: `ROLE_ADMIN` short-circuits to allow at line 40 — the new admin read-only controller relies on this (and on the existing `VIEW` attribute).

**Customer / landlord / admin entry surfaces today**

- `templates/portal/user/order/detail.html.twig`: no handover reference (grep confirmed). Logged-in customers literally have no in-product link.
- `templates/portal/landlord/order/detail.html.twig`: no handover reference.
- `templates/admin/order/detail.html.twig`: no handover reference.
- `templates/public/order_status.html.twig`: no handover reference; documents panel is `templates/components/order_status_documents.html.twig`.
- `templates/components/order_documents.html.twig`: portal documents panel; has sections "Smluvní dokumenty" / "Pobočka" / "Právní a obchodní dokumenty" but nothing for handover.

**Existing routes the spec reuses**

- Customer logged-in form: `/portal/predavaci-protokol/{id}` (`Portal\User\HandoverViewController`).
- Customer passwordless form (signed link): `/predavaci-protokol/{id}?_hash=…` (`Public\HandoverViewController`).
- Landlord form: `/portal/pronajimatel/predavaci-protokol/{id}` (`LandlordHandoverViewController`).

**Admin dashboard + alerts pattern (spec 023, model to mirror)**

- `src/Controller/Admin/AdminOverdueController.php` (`#[Route('/portal/admin/po-splatnosti', name: 'admin_overdue')]`): builds `OverdueChecker::findOverdueViews($now)` + `summarise($now)` and renders `templates/admin/overdue/list.html.twig`.
- `src/Service/Overdue/OverdueChecker.php`: `summarise()` returns `OverdueSummary` (count, totalAmount, top: 5). `findOverdueViews()` returns sorted `OverdueContractView[]`.
- `src/Twig/OverdueExtension.php` exposes `overdue_count()` for sidebar/dashboard badge (scalar SQL count, no hydration).
- `templates/portal/layout.html.twig:128-139`: sidebar entry with badge — copy this pattern verbatim for the new "Operace" entry.
- `templates/portal/dashboard_admin.html.twig:9-33`: top-of-dashboard alert card (red when count > 0, green when 0). Operations gets a sibling card.
- `templates/admin/order/detail.html.twig:52-62`: `isUserOverdue` banner. The handover banner sits next to it (above onboarding banner).

**Existing similar signals already detected elsewhere (Operations hub aggregates them)**

- "Smlouvy končící do 7 dní" — `ContractRepository::findExpiringWithinDays(7, $now)` already exists (used by the handover-creation cron).
- "Onboarding podepsaný ale nezaplacený" — already tracked by `OnboardingReminderSent` (spec 043). `OrderRepository` has the candidates query at line 120 (`findOnboardingReminderCandidates`).
- "Externí předplatné brzy končící (≤7 dní)" — already tracked by `app:send-external-prepayment-ending-soon` (spec 025); `ContractRepository::findExternalPrepaymentEndingWithinDays(7, $now)` exists.

## Architecture

```
                       ┌──────────────────────────────────────┐
                       │  /portal/admin/operace               │
                       │  AdminOperationsController           │
                       │  → OperationsAlertsBuilder::build()  │
                       └──────┬───────────────────────────────┘
                              │
              ┌───────────────┼─────────────────┬──────────────────────┐
              ▼               ▼                 ▼                      ▼
   HandoverProtocolRepo  ContractRepo      OrderRepo            OverdueChecker
   ::findPending()       ::findExpiring    ::findOnboarding     (link out only —
                         WithoutProtocol   ReminderCandidates    summary card only,
                         ::findExternal                          full list stays at
                         PrepaymentEnding                        /portal/admin/po-splatnosti)


   /portal/objednavky/{id}                     /objednavka/{id}/stav (public, signed)
        │                                                │
        │ HandoverBanner (status-aware)                  │ HandoverBanner (signed link)
        │ ─────────────────────────────────────          │ ─────────────────────────────
        │ Status = PENDING / TENANT_COMPLETED            │ status = PENDING / TC / LC
        │ → click → /portal/predavaci-protokol/{id}      │ → click → freshly-minted
        │                                                │   HandoverUrlGenerator::
        │ Status = COMPLETED                             │   generateTenantView($p)
        │ → row in components/order_documents            │ → row in components/
        │                                                │   order_status_documents

   /portal/landlord/orders/{id}                /portal/admin/orders/{id}
        │                                                │
        │ HandoverBanner                                 │ HandoverBanner (read-only)
        │ → /portal/pronajimatel/                        │ → /portal/admin/
        │   predavaci-protokol/{id}                      │   predavaci-protokol/{id}
        │                                                │   (NEW read-only view)
```

The five surfaces share one Twig partial — `templates/components/handover_banner.html.twig` — parameterised by `actor` ('tenant' | 'landlord' | 'admin') and `viewUrl`. The hub itself reuses zero partials (it's a single-file rich list).

## Requirements

### 1. `HandoverStatus` Czech labels + actor-aware helpers

`src/Enum/HandoverStatus.php` — add label methods. Each banner / hub section reads from here so wording stays consistent.

```php
public function label(): string
{
    return match ($this) {
        self::PENDING => 'Čeká na vyplnění',
        self::TENANT_COMPLETED => 'Čeká na pronajímatele',
        self::LANDLORD_COMPLETED => 'Čeká na nájemce',
        self::COMPLETED => 'Vyplněno',
    };
}

public function isWaitingOn(string $actor): bool
{
    return match ([$this, $actor]) {
        [self::PENDING, 'tenant'], [self::PENDING, 'landlord'] => true,
        [self::TENANT_COMPLETED, 'landlord'] => true,
        [self::LANDLORD_COMPLETED, 'tenant'] => true,
        default => false,
    };
}

public function badgeClass(): string  // for hub table rows + admin badge
{
    return match ($this) {
        self::PENDING => 'badge-warning',
        self::TENANT_COMPLETED, self::LANDLORD_COMPLETED => 'badge-info',
        self::COMPLETED => 'badge-success',
    };
}
```

Update the cron at `ProcessHandoverProtocolsCommand.php:57` to use the new label via `HandoverStatusFormatter` only if logging copy is changed — no other production callers rely on label-equivalent strings today, so this is additive.

### 2. New `templates/components/handover_banner.html.twig`

Shared status-aware banner used on all four order detail templates + the public `/stav` page. Signature:

```twig
{# Inputs:
   - protocol: HandoverProtocol
   - actor: 'tenant' | 'landlord' | 'admin'
   - viewUrl: string  (where the click leads — already actor-correct)
#}
```

Two visual states:

- **Pending / partial** (`not protocol.isFullyCompleted`): amber alert card with `<svg>` icon, headline "Předávací protokol" + body line keyed off `protocol.status.isWaitingOn(actor)` ("Vyplňte protokol, abychom mohli ukončit nájem." vs. "Čeká se na druhou stranu."), CTA button "Otevřít protokol". For `actor == 'admin'` only: extra subline "Vytvořeno {{ daysSinceCreated }} {{ dní|den|dny }}" so admins see the dwell time.
- **Completed**: green compact row (smaller card) with checkmark, "Předávací protokol vyplněn dd.mm.yyyy", CTA "Zobrazit".

Banner is rendered in this exact slot on each page (immediately above the existing first card / banner stack):

| Page | Slot | viewUrl |
|---|---|---|
| `templates/portal/user/order/detail.html.twig` | after `customer_billing_status` include (line ~104) | `path('portal_user_handover_view', {id: protocol.id})` |
| `templates/public/order_status.html.twig` | top of body, after status badge | freshly minted via `HandoverUrlGenerator::generateTenantView($protocol)` in the controller |
| `templates/portal/landlord/order/detail.html.twig` | after the "Smlouva brzy končí" expiration warning (line ~67) | `path('portal_landlord_handover_view', {id: protocol.id})` |
| `templates/admin/order/detail.html.twig` | between the `isUserOverdue` banner and `_onboarding_banner.html.twig` (line ~63) | `path('admin_handover_view', {id: protocol.id})` |

In each case the include is gated by `{% if protocol %}` — the parent controllers must pass `protocol` (nullable).

### 3. Parent-controller wiring for the banner

Each of the 4 order-detail controllers loads the optional protocol once and passes it:

- `src/Controller/Portal/User/OrderDetailController.php`, `src/Controller/Portal/LandlordOrderDetailController.php`, `src/Controller/Admin/AdminOrderDetailController.php`:
  ```php
  $protocol = null !== $contract ? $this->handoverProtocolRepository->findByContract($contract) : null;
  // ... add 'protocol' => $protocol to render() args
  ```
- `src/Controller/Public/OrderStatusController.php`: same pattern, but also mint the signed tenant URL:
  ```php
  $handoverUrl = $protocol !== null ? $this->handoverUrlGenerator->generateTenantView($protocol) : null;
  // pass both 'protocol' and 'handoverUrl' to the template
  ```
  The signed URL re-mint is safe because the controller has already validated the order-status signature — same trust gradient as the existing contract / invoice / VOP signed-download links on the page. Inject `HandoverUrlGenerator` (already exists; no new service needed).

### 4. Documents-panel row when `COMPLETED`

`templates/components/order_documents.html.twig` (portal) and `templates/components/order_status_documents.html.twig` (public `/stav`) — add a "Předávací protokol" row inside the "Smluvní dokumenty" `<ul>` immediately after the `Nájemní smlouva` row, gated by `{% if protocol and protocol.isFullyCompleted %}`. Row text: "Vyplněn dd.mm.yyyy". `viewHref` = the actor-appropriate handover view URL (portal: `portal_user_handover_view`; `/stav`: re-signed `HandoverUrlGenerator::generateTenantView`). `downloadHref` = null for now (no PDF export this spec — disabled-state arrow icon doesn't appear; the row uses the `documentRow` macro which already handles null `downloadHref` gracefully). Pass `protocol` into the partial via the existing `with {…}` block on each include site.

For `templates/portal/landlord/order/detail.html.twig` and `templates/admin/order/detail.html.twig`, both already have a "Dokument smlouvy" download card — add a sibling card directly below it gated on `{% if protocol and protocol.isFullyCompleted %}` linking to the actor-appropriate read-only handover view. Copy the existing card markup so visual rhythm matches.

### 5. New admin read-only view `/portal/admin/predavaci-protokol/{id}`

New single-action controller `src/Controller/Admin/AdminHandoverViewController.php`:

```php
#[Route('/portal/admin/predavaci-protokol/{id}', name: 'admin_handover_view', requirements: ['id' => '[0-9a-f-]{36}'])]
#[IsGranted('ROLE_ADMIN')]
final class AdminHandoverViewController extends AbstractController
{
    public function __construct(
        private readonly HandoverProtocolRepository $handoverProtocolRepository,
    ) {}

    public function __invoke(string $id): Response
    {
        $protocol = $this->handoverProtocolRepository->get(Uuid::fromString($id));
        $this->denyAccessUnlessGranted(HandoverProtocolVoter::VIEW, $protocol);
        // ROLE_ADMIN short-circuit at HandoverProtocolVoter.php:40 grants this automatically;
        // keep the explicit check for symmetry with the other handover controllers.

        $contract = $protocol->contract;
        return $this->render('admin/handover/view.html.twig', [
            'protocol' => $protocol,
            'contract' => $contract,
            'storage' => $contract->storage,
            'place' => $contract->storage->getPlace(),
        ]);
    }
}
```

New template `templates/admin/handover/view.html.twig`:

- Breadcrumb: Objednávky → Detail → Předávací protokol (each linked).
- Top: customer card + storage card + protocol status pill (uses `HandoverStatus::badgeClass()`).
- Timeline section: 4 rows — Vytvořeno, Nájemce vyplnil (or "Čeká"), Pronajímatel vyplnil (or "Čeká"), Dokončeno (or em-dash). Each row shows the timestamp with `date('d.m.Y H:i')` when present, "Čeká {{ days since createdAt }} {{ dní }}" otherwise.
- Two read-only sections: "Nájemce" (renders `tenantComment` + photos from `getTenantPhotos()`) and "Pronajímatel" (renders `landlordComment` + `newLockCode` + photos from `getLandlordPhotos()`). Each photo links to its full-size via `upload_url(photo.path)` (the existing helper used in the existing portal handover view).
- Footer: links to "Detail objednávky" + "Zobrazit smlouvu". No edit / submit forms — admin is purely observing.

The admin view is the documentation-panel target (Requirement 4) when `COMPLETED`, so it also has to handle the completed state cleanly: completed handovers render identically (same structure), just with both timestamps populated.

### 6. New `App\Service\Operations\OperationsAlertsBuilder`

```php
final readonly class OperationsAlertsBuilder
{
    public function __construct(
        private HandoverProtocolRepository $handoverProtocolRepository,
        private ContractRepository $contractRepository,
        private OrderRepository $orderRepository,
        private OverdueChecker $overdueChecker,
    ) {}

    public function build(\DateTimeImmutable $now): OperationsAlertSummary { /* … */ }

    public function totalPendingCount(\DateTimeImmutable $now): int { /* scalar SQL only */ }
}
```

`OperationsAlertSummary` (value object, readonly) groups counts + result-sets:

```php
final readonly class OperationsAlertSummary
{
    public function __construct(
        // Handover bucket
        public int $handoverWaitingTenantCount,
        public int $handoverWaitingLandlordCount,
        public int $handoverOverdueCount,           // pending > 14 days past createdAt
        /** @var HandoverProtocol[] */
        public array $handoverViews,
        // Contract bucket
        public int $contractsEndingWithoutProtocolCount,
        /** @var Contract[] */
        public array $contractsEndingWithoutProtocol,
        // Onboarding bucket
        public int $onboardingSignedUnpaidCount,
        /** @var Order[] */
        public array $onboardingSignedUnpaid,
        // External prepayment bucket
        public int $externalPrepaymentEndingCount,
        /** @var Contract[] */
        public array $externalPrepaymentEnding,
        // Overdue (link-out only — full list stays on /portal/admin/po-splatnosti)
        public int $overdueCount,
        public int $overdueAmount,
        // Aggregate
        public int $totalPending,
    ) {}
}
```

Bucketing rules (all keyed on `Contract.terminatedAt is null` unless noted):

- **handoverWaitingTenant**: `HandoverStatus IN (PENDING, LANDLORD_COMPLETED)` and not completed — protocol needs the tenant.
- **handoverWaitingLandlord**: `HandoverStatus IN (PENDING, TENANT_COMPLETED)` — protocol needs the landlord.
  Note: a `PENDING` protocol counts in BOTH buckets (truly nobody acted yet). Display this explicitly in the hub header copy ("Některé protokoly čekají na obě strany současně"). Don't double-sum at the totalPending level — `totalPending` uses `count(distinct hp.id where not isFullyCompleted)`.
- **handoverOverdue**: not completed AND `now - createdAt > 14 days`. Red background row.
- **contractsEndingWithoutProtocol**: `Contract.endDate is not null AND endDate BETWEEN $now AND $now + 7 days AND terminatedAt is null AND no HandoverProtocol row exists`. The cron creates these the next day at 7-d boundary; this row catches the gap when the cron hasn't fired yet (or failed) — operationally most useful as a sanity check.
- **onboardingSignedUnpaid**: reuse `OrderRepository::findOnboardingReminderCandidates($now)` from spec 043 (already excludes paid).
- **externalPrepaymentEnding**: reuse `ContractRepository::findExternalPrepaymentEndingWithinDays(7, $now)` from spec 025.
- **overdue**: reuse `OverdueChecker::summarise($now)` (count + totalAmount only — the hub links out to `/portal/admin/po-splatnosti` for the full list; never re-renders the table).

`totalPending` sums every count except `overdueCount` (overdue has its own sidebar item — including it would double-count the badge).

### 7. New `HandoverProtocolRepository::findPending(\DateTimeImmutable $now): array`

Append to `src/Repository/HandoverProtocolRepository.php`:

```php
/** @return HandoverProtocol[] sorted: status (PENDING first), then createdAt ASC */
public function findPending(\DateTimeImmutable $now): array
{
    return $this->entityManager->createQueryBuilder()
        ->select('hp')
        ->from(HandoverProtocol::class, 'hp')
        ->join('hp.contract', 'c')
        ->where('hp.status != :completed')
        ->setParameter('completed', HandoverStatus::COMPLETED->value)
        ->orderBy('hp.createdAt', 'ASC')
        ->getQuery()
        ->getResult();
}

public function countPending(): int
{
    return (int) $this->entityManager->createQueryBuilder()
        ->select('COUNT(hp.id)')
        ->from(HandoverProtocol::class, 'hp')
        ->where('hp.status != :completed')
        ->setParameter('completed', HandoverStatus::COMPLETED->value)
        ->getQuery()
        ->getSingleScalarResult();
}
```

### 8. New `ContractRepository::findExpiringWithoutProtocol(int $days, \DateTimeImmutable $now): array`

Append:

```php
/** @return Contract[] */
public function findExpiringWithoutProtocol(int $days, \DateTimeImmutable $now): array
{
    $threshold = $now->modify("+{$days} days");

    return $this->entityManager->createQueryBuilder()
        ->select('c')
        ->from(Contract::class, 'c')
        ->leftJoin(HandoverProtocol::class, 'hp', 'WITH', 'hp.contract = c')
        ->where('c.endDate IS NOT NULL')
        ->andWhere('c.endDate BETWEEN :now AND :threshold')
        ->andWhere('c.terminatedAt IS NULL')
        ->andWhere('hp.id IS NULL')
        ->setParameter('now', $now)
        ->setParameter('threshold', $threshold)
        ->orderBy('c.endDate', 'ASC')
        ->getQuery()
        ->getResult();
}
```

### 9. New `App\Controller\Admin\AdminOperationsController` + view

```php
#[Route('/portal/admin/operace', name: 'admin_operations')]
#[IsGranted('ROLE_ADMIN')]
final class AdminOperationsController extends AbstractController
{
    public function __construct(
        private readonly OperationsAlertsBuilder $builder,
        private readonly ClockInterface $clock,
    ) {}

    public function __invoke(): Response
    {
        $summary = $this->builder->build($this->clock->now());
        return $this->render('admin/operations/list.html.twig', ['summary' => $summary, 'now' => $this->clock->now()]);
    }
}
```

`templates/admin/operations/list.html.twig` — sectioned page (mirrors `templates/admin/overdue/list.html.twig` structure):

1. **Header**: "Operace" + "Co dnes potřebuje vaši pozornost" subtitle. Top summary card grid (4 cards): "Předávací protokoly čekají", "Smlouvy končící", "Onboarding bez platby", "Po splatnosti" (last is a link to `/portal/admin/po-splatnosti`, not data-rich on this page).
2. **Section: Předávací protokoly** — `summary.handoverViews`. Table columns: `Zákazník` / `Sklad` / `Stav` (uses `HandoverStatus::label()` + `badgeClass()`) / `Čeká od` (`createdAt`, computed days) / `Akce` (link to `admin_handover_view`). Rows where `days > 14` get `bg-red-50` for visual urgency.
3. **Section: Smlouvy končící bez protokolu (≤7 dní)** — `summary.contractsEndingWithoutProtocol`. Columns: `Zákazník` / `Sklad` / `Končí` / `Akce` (link to `admin_order_detail`). Short list; helper notice "Protokol se vytváří automaticky 7 dní před koncem nájmu — pokud zde čekající smlouva sedí déle, zkontrolujte cron."
4. **Section: Onboarding podepsaný bez platby** — `summary.onboardingSignedUnpaid`. Columns: `Zákazník` / `Pobočka` / `Vytvořeno` / `Akce`.
5. **Section: Externí předplatné brzy končící** — `summary.externalPrepaymentEnding`. Columns: `Zákazník` / `Sklad` / `Předplaceno do` / `Akce`.

When a section is empty, render the section heading + a green confirmation row ("Žádné protokoly nečekají na vyplnění."). Do **not** hide empty sections — keeping them visible reinforces "today: clear" rather than "today: section missing".

### 10. Sidebar nav + count Twig extension

- Append a sidebar entry to `templates/portal/layout.html.twig` in **both** desktop sidebar (line ~128 area, between the Po-splatnosti and Odeslané-e-maily entries) and mobile menu (line ~323 area — same paired structure). Use the same badge pattern as Po splatnosti, with the new `operations_alerts_count()` Twig function.
- New `src/Twig/OperationsExtension.php` — single function `operations_alerts_count(): int` that delegates to `OperationsAlertsBuilder::totalPendingCount($clock->now())`. Implementation MUST be scalar SQL (no hydration) — mirrors `OverdueExtension::overdueCount()`. Internally that's: `handoverProtocolRepository->countPending()` + `contractRepository->countExpiringWithoutProtocol(7, $now)` + `orderRepository->countOnboardingReminderCandidates($now)` + `contractRepository->countExternalPrepaymentEndingWithinDays(7, $now)`. Add scalar `count*` siblings to whichever repositories don't already have them.
- Icon: bell-with-dot or list-checkbox; pick a Heroicons outline icon distinct from the Po-splatnosti exclamation-triangle.

### 11. Admin dashboard tile (existing `templates/portal/dashboard_admin.html.twig`)

Directly under the existing Po-splatnosti alert card (line 33), add a sibling card for Operations:

- When `summary.totalPending > 0`: amber card with text "{{ count }} úkol{{ ý|y|ů }} k vyřízení" + link to `/portal/admin/operace`.
- When `summary.totalPending == 0`: green card "Žádné čekající úkoly".

This needs the existing `GetDashboardStatsQuery` to also fetch the totals — extend `GetDashboardStatsResult` with one extra field `int $operationsPendingCount` populated via `OperationsAlertsBuilder::totalPendingCount()`. Mirror the existing `overdueCount` field plumbing.

### 12. Tests

- `tests/Unit/Enum/HandoverStatusTest.php`: every `label()`, `isWaitingOn()`, `badgeClass()` branch.
- `tests/Unit/Service/Operations/OperationsAlertsBuilderTest.php`: fixture-driven; seed protocols in each status + a contract ending in 5 days without protocol + an unpaid signed order + an externally-prepaid contract ending in 4 days; assert each bucket count, assert `totalPending` does not include overdueCount, assert `PENDING` protocol counts toward both `handoverWaitingTenant` and `handoverWaitingLandlord` buckets, assert that protocols >14 days old populate `handoverOverdueCount`.
- `tests/Integration/Controller/Admin/AdminOperationsControllerTest.php`: integration test that hits `/portal/admin/operace`, asserts each section heading present, asserts handover row links to `admin_handover_view`.
- `tests/Integration/Controller/Admin/AdminHandoverViewControllerTest.php`: ROLE_USER → 403; ROLE_LANDLORD on unrelated place → 403; ROLE_ADMIN → 200 with read-only sections present.
- `tests/Integration/Controller/Public/OrderStatusControllerTest.php` (extend existing): when handover exists, response body contains the banner with a signed handover URL (assert `_hash=` present in href).

### 13. Fixtures

Extend `src/DataFixtures/HandoverProtocolFixtures.php` (create if absent — there's currently no fixture, only command tests build them ad-hoc) to seed:
- One `PENDING` protocol (created 3 days ago) → user "user@example.com".
- One `TENANT_COMPLETED` protocol (created 5 days ago, tenant filled yesterday) → another contract.
- One handover-overdue `PENDING` (created 16 days ago) → another contract — exercises the red-row path.
- One `COMPLETED` protocol → exercises the documents-panel row path.

Reuse existing landlord / tenant fixture users; bind to existing `Contract` fixtures.

## Acceptance

- [ ] `composer quality` green.
- [ ] `composer test` green (1100+ tests).
- [ ] Logged in as `user@example.com`, opening `/portal/objednavky/{id}` for a contract with a `PENDING` handover shows the amber banner with "Otevřít protokol" CTA reaching `/portal/predavaci-protokol/{id}`. After tenant submits, page shows the green completed row in documents panel.
- [ ] Opening `/objednavka/{id}/stav?_hash=…` for the same order shows the same banner; the CTA href is a freshly-signed `/predavaci-protokol/{id}?_hash=…` (signature validates).
- [ ] Logged in as `landlord@example.com`, opening `/portal/landlord/orders/{id}` for a contract on their place with a `LANDLORD_COMPLETED`-needed protocol shows the banner linking to `/portal/pronajimatel/predavaci-protokol/{id}`.
- [ ] Logged in as `admin@example.com`:
  - Opening `/portal/admin/orders/{id}` shows the banner with "Vytvořeno X dní" subline + link to `/portal/admin/predavaci-protokol/{id}`.
  - That route renders a read-only page: status pill, timeline, both submitted sections, no form / submit buttons anywhere.
  - Sidebar shows "Operace" entry with red count badge equal to the number of pending operations.
  - `/portal/admin/operace` lists every section (handover / contracts ending / onboarding / external prepayment), empty sections show green "Žádné" rows.
  - Admin dashboard top shows the Operations alert card next to the Po-splatnosti card; clicking it lands on the hub.
- [ ] `ROLE_USER` hitting `/portal/admin/predavaci-protokol/{id}` returns 403. `ROLE_LANDLORD` of an unrelated place returns 403. `ROLE_LANDLORD` of the same place returns 403 (admin route — landlord uses their own).
- [ ] Documents panel on customer portal + `/stav` shows the "Předávací protokol — Vyplněn dd.mm.yyyy" row only after the protocol is `COMPLETED`.

## Out of scope

- **PDF export of completed handover protocol**: link-only archival in this spec. PDF generation can mirror `VopPdfStamper` / `OrderContractDownloadController` in a follow-up spec.
- **Operator-side handover actions** (e.g. admin manually marking complete, admin nudge button that re-triggers reminder e-mail): admin view stays read-only this iteration. The `ProcessHandoverProtocolsCommand` cron already handles reminders.
- **Landlord-side admin assistance**: admins observing a landlord-pending protocol don't get a "complete on landlord's behalf" button. If that becomes operationally important, separate spec.
- **Mobile menu for the new sidebar entry**: layout already has paired desktop/mobile blocks (`layout.html.twig:128 + :323`); both get the new entry. Beyond that, no responsive tuning.
- **Per-place / per-landlord operations hubs**: the hub is admin-global only. Landlords have their per-place dashboard (spec 024) for place-scoped signals. Adding "operations" to that is a separate spec.
- **Push / Slack / e-mail digest of operations summary**: e-mail digest mirrors spec 031's daily overdue digest pattern — if needed, follow-up spec. This spec is in-product UI only.
- **Re-using `OverdueDigestSent`-style daily idempotency rows for operations notifications**: same reason, follow-up.

## Open questions

None — proceed.
