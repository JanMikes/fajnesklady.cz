# 030 — Customer-facing reflection of free / external-prepayment contracts

**Status:** ready
**Type:** UX / templates
**Scope:** small (~6 files: 1 new shared partial + 2 templates touched + 1 entity helper + 2 integration tests)
**Depends on:** spec 025 (entity fields, admin banner) — done

## Problem

Spec 025 introduced two non-standard billing arrangements: **free contracts** (`Contract.individualMonthlyAmount = 0`, no charge, no invoice) and **externally pre-paid contracts** (`Contract.paidThroughDate` set, `goPayParentPaymentId` null until conversion). Admin sees a clear banner on the order detail (`templates/admin/order/_onboarding_banner.html.twig`) and badges in the order list — but the **customer sees nothing different** in either the authenticated portal (`/portal/objednavky/{id}`) or the public order-status permalink (`/objednavka/{id}/stav`).

The concrete pain: a customer who receives the "externí předplatné brzy končí" e-mail (cron `app:send-external-prepayment-ending-soon`, spec 025) clicks the order-status link in their inbox and sees a normal Detail page with a generic "Měsíční platba 800 Kč / měsíc" line — no mention of "you've prepaid through 30.11.2026", no hint that something will change after that date, no acknowledgement of the e-mail they just opened. Free customers similarly see a price for a contract they will never be charged for, which is confusing and slightly alarming.

## Goal

Both customer-facing surfaces — the authenticated portal order detail and the public `/stav` permalink — clearly indicate, when applicable, the contract's billing status using one of three states:

1. **Zdarma** — green "Pronájem zdarma" badge + short note "Tato smlouva nepodléhá platbám." Always shown when `Contract.individualMonthlyAmount === 0`.
2. **Externí předplatné (≥ 8 dní zbývá)** — neutral/blue banner: "Předplaceno do {dd.mm.yyyy}. Po tomto datu se obnoví běžné měsíční platby." Shown when `Contract.paidThroughDate` is set, in the future, and `Contract.goPayParentPaymentId` is null.
3. **Externí předplatné, blíží se konec (≤ 7 dní)** — amber/warning banner with the same date plus a stronger framing and a CTA: "Předplatné vyprší {dd.mm.yyyy}. Pro pokračování bez přerušení nás prosím kontaktujte na simek@fajnesklady.cz a nastavíme automatickou platbu." Mirrors the wording of the reminder e-mail.

These render only on customer-facing pages. Admin and landlord views already have their own banners (spec 025) and are explicitly **out of scope** here.

## Context (current state)

### Entity already has the data

- `Contract.individualMonthlyAmount` (`src/Entity/Contract.php:77`) — `?int` halere; null = standard, 0 = free, >0 = individual. Helpers already in place: `isFree(): bool` (line 286), `hasIndividualPrice(): bool` (line 281), `getEffectiveMonthlyAmount(): int` (line 276).
- `Contract.paidThroughDate` (`src/Entity/Contract.php:55`) — `?\DateTimeImmutable`, set by both standard recurring billing AND `Contract::markExternallyPrepaid()` (line 297).
- `Contract.goPayParentPaymentId` (`src/Entity/Contract.php:34`) — null for externally-prepaid contracts; once a customer converts via the (deferred) self-service flow, it gets a value and the contract leaves the "externí předplatné" state. `hasActiveRecurringPayment()` (line 201) returns `null !== $this->goPayParentPaymentId && !$this->isTerminated()` — useful as the *negation* gate for the banner.
- **There is no existing `daysUntilExternalPrepaymentEnds()` helper.** Templates today compute days remaining via `ContractService::getDaysRemaining()` (`src/Service/ContractService.php:164`) which targets `endDate`, not `paidThroughDate`. We add a small dedicated helper rather than overloading the existing one.

### Customer surfaces to touch

- **Portal**: `templates/portal/user/order/detail.html.twig`. Already extends `portal/layout.html.twig`, has `contract` in scope (passed by `Portal\User\OrderDetailController`, line 56). Line 88 already includes `components/order_access_code.html.twig` — the new partial slots in alongside it. The `OrderDetailController` already injects `ClockInterface` (line 14) — no controller change needed.
- **Public `/stav`**: `templates/public/order_status.html.twig`. Has `contract` exposed via `vm.contract` (`OrderStatusViewModel`, see `src/Service/Order/OrderStatusViewModelFactory.php:102`). Uses `vm` namespacing so we pass `contract: vm.contract` into the include. The factory does not need to expose anything new — the contract entity carries the helpers.

### Reference: admin banner partial

`templates/admin/order/_onboarding_banner.html.twig` (already exists from spec 025) is the visual reference for tone but **not** a layout reuse target — it reads from `order.*` and is admin-tone ("Tato objednávka byla vytvořena adminem"). Our partial reads from `contract.*` and is customer-tone ("Pronájem zdarma" / "Předplaceno externě do …").

### Why a shared partial (not two copy-pastes)

Both customer surfaces speak in identical voice and show identical content, only the surrounding chrome differs (Tailwind grid in portal, card stack in `/stav`). One partial, two `{% include %}`s — the diff stays trivially auditable when wording changes, and the integration tests can assert the same string on both pages.

### Where the partial sits visually

- **Portal**: above the existing two-column `Informace o objednávce` / `Skladová jednotka` grid (line 90), under the renewal CTA (line 86) and access-code component (line 88). It's the most relevant info for a customer arriving from the reminder e-mail.
- **Public `/stav`**: directly under the status banner / CTA row (line 64), above the "Detail objednávky" card (line 70). Same visual hierarchy.

## Architecture

```
   ┌──────────────────────────────────────────────────────────────┐
   │  Contract entity (src/Entity/Contract.php)                   │
   │  ── existing: isFree(), paidThroughDate, goPayParentPaymentId│
   │  ── NEW: daysUntilExternalPrepaymentEnds(\DateTimeImmutable) │
   │            : ?int                                            │
   │      null  → not externally prepaid (paidThroughDate null,   │
   │              or already converted to GoPay,                  │
   │              or contract terminated)                         │
   │      int   → calendar days from $now to paidThroughDate;     │
   │              negative if past (but the banner only renders   │
   │              for non-negative values, see partial logic)     │
   └──────────────────────────────────────────────────────────────┘
                                ▲
                                │ called from the partial below
                                │
   ┌──────────────────────────────────────────────────────────────┐
   │  templates/components/customer_billing_status.html.twig      │
   │  ── inputs: contract (Contract|null), now (\DateTimeImmutable│
   │             — defaults to "now"|date_modify("0 sec") if not  │
   │             passed, but the includes always pass it)         │
   │  ── renders one of:                                          │
   │       (a) green "Pronájem zdarma" badge + note               │
   │       (b) blue "Předplaceno do dd.mm.yyyy" banner (>7 days)  │
   │       (c) amber "Předplatné brzy končí" banner (0..7 days)   │
   │  ── renders nothing when contract is null OR no flag applies │
   └──────────────────────────────────────────────────────────────┘
                                ▲
                                │
        ┌───────────────────────┴────────────────────────┐
        │                                                │
   ┌─────────────────────────────┐    ┌─────────────────────────────────┐
   │ portal/user/order/detail    │    │ public/order_status             │
   │ {% include … with {contract,│    │ {% include … with {contract:    │
   │   now: app.now} only %}     │    │   vm.contract, now: app.now}    │
   │                             │    │   only %}                       │
   └─────────────────────────────┘    └─────────────────────────────────┘
```

`app.now` is not a global — Twig has no implicit clock. We pass an actual `\DateTimeImmutable` from the controller to the template (portal already injects `ClockInterface`; public `/stav` controller does not, but the view-model factory does — easier to expose `now` on the view-model).

## Requirements

### 1. Entity helper: `Contract::daysUntilExternalPrepaymentEnds()`

Add to `src/Entity/Contract.php`, near the existing `markExternallyPrepaid()` (line 297):

```php
/**
 * Calendar days from $now to $this->paidThroughDate for an externally-
 * prepaid contract. Returns null when this contract is NOT in the
 * "externally prepaid, not yet converted" state — i.e. when:
 *   - paidThroughDate is null (no prepayment recorded), OR
 *   - goPayParentPaymentId is set (customer already converted to GoPay), OR
 *   - the contract is terminated.
 *
 * Returns a negative integer when the prepayment has already lapsed —
 * the customer-facing partial uses 0..7 as the "ending soon" band and
 * treats >7 as "future" / <0 as "lapsed, hide" — see template logic.
 */
public function daysUntilExternalPrepaymentEnds(\DateTimeImmutable $now): ?int
{
    if (null === $this->paidThroughDate) {
        return null;
    }
    if (null !== $this->goPayParentPaymentId) {
        return null;
    }
    if ($this->isTerminated()) {
        return null;
    }

    $today = $now->setTime(0, 0, 0);
    $end = $this->paidThroughDate->setTime(0, 0, 0);

    return (int) $today->diff($end)->format('%r%a');
}
```

The `%r%a` format includes the sign (`-` for past dates), so `1.diff(today.minus(2)) → -2`. Default `diff().days` is unsigned — verified-tested in req. 4.

### 2. View-model: expose `now` to the public status template

`src/Service/Order/OrderStatusViewModel.php` — add a `\DateTimeImmutable $now` field (constructor + readonly).

`src/Service/Order/OrderStatusViewModelFactory.php` — inject `Psr\Clock\ClockInterface $clock`, capture `$now = $this->clock->now()` at the top of `build()` and pass it to the `OrderStatusViewModel` constructor.

Why on the view-model and not on the controller: `OrderStatusController` is a thin pass-through, the factory already orchestrates everything. Adding `now` here keeps the controller untouched.

### 3. Shared partial: `templates/components/customer_billing_status.html.twig`

```twig
{# Customer-facing badge / banner reflecting non-standard billing state.
   Renders nothing for vanilla recurring contracts.

   Inputs:
     contract  Contract|null  — null hides everything
     now       \DateTimeImmutable  — used for the 7-day window calculation
#}
{% if contract is not null %}
    {% if contract.isFree() %}
        <div class="mb-4 inline-flex items-center gap-2 rounded-md bg-green-50 border border-green-200 px-3 py-2">
            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-green-100 text-green-800">
                Pronájem zdarma
            </span>
            <span class="text-sm text-green-800">Tato smlouva nepodléhá platbám.</span>
        </div>
    {% else %}
        {% set daysLeft = contract.daysUntilExternalPrepaymentEnds(now) %}
        {% if daysLeft is not null and daysLeft >= 0 %}
            {% if daysLeft <= 7 %}
                {# Amber: prepayment expires in ≤7 days, customer must act. #}
                <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-4">
                    <div class="flex items-start gap-3">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-amber-600 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                        <div class="flex-1 text-sm text-amber-900">
                            <strong>Externí předplatné brzy končí.</strong>
                            Předplatné vyprší {{ contract.paidThroughDate|date('d.m.Y') }}.
                            Pro pokračování bez přerušení nás prosím kontaktujte na
                            <a href="mailto:simek@fajnesklady.cz" class="link font-medium">simek@fajnesklady.cz</a>
                            a nastavíme automatickou platbu.
                        </div>
                    </div>
                </div>
            {% else %}
                {# Blue: prepayment safely in the future. #}
                <div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 p-4">
                    <div class="flex items-start gap-3">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-blue-600 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <div class="flex-1 text-sm text-blue-900">
                            <strong>Předplaceno externě do {{ contract.paidThroughDate|date('d.m.Y') }}.</strong>
                            Po tomto datu se obnoví běžné měsíční platby.
                        </div>
                    </div>
                </div>
            {% endif %}
        {% endif %}
    {% endif %}
{% endif %}
```

**Wording note**: matches the reminder-email tone (spec 025 req. 13). The contact e-mail `simek@fajnesklady.cz` is the same address already used elsewhere in `templates/public/order_status.html.twig:170,178` and the failed-billing card.

**Mutual exclusivity**: free + paidThroughDate is technically possible (admin chose `monthlyPriceMode = 'free'` AND ticked `Externí předplatné`). The partial chooses **free wins** — for a zero-priced contract there is no recurring charge to resume after the prepayment, so the prepayment banner would be misleading. The form in spec 025 doesn't actually prevent this combination; we're defining the customer-facing display order, not the form rules. (If the user later wants to forbid that combo at the form layer, that's a separate spec.)

### 4. Wire the partial into both customer surfaces

#### `templates/portal/user/order/detail.html.twig`

Insert after line 88 (after the existing `order_access_code.html.twig` include):

```twig
{% include 'components/customer_billing_status.html.twig' with {
    contract: contract,
    now: now,
} only %}
```

`OrderDetailController` already has `ClockInterface` in its constructor (line 33) — pass `'now' => $this->clock->now()` in the render context (line 70-80). One-line addition to the existing array.

#### `templates/public/order_status.html.twig`

Insert after the CTA row (line 63), before the `<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">` (line 65):

```twig
{% include 'components/customer_billing_status.html.twig' with {
    contract: vm.contract,
    now: vm.now,
} only %}
```

`vm.now` is exposed by the view-model change in req. 2.

### 5. Tests

Place tests according to existing conventions (`tests/Unit/` for entity logic, `tests/Integration/` for full HTTP-level controller tests).

#### Unit — `tests/Unit/Entity/ContractTest.php`

Extend (or create if file doesn't exist; spec 025 added one — extend):

- `daysUntilExternalPrepaymentEnds` returns null when `paidThroughDate` is null.
- Returns null when `goPayParentPaymentId` is set (already-converted contract).
- Returns null when contract is terminated.
- Returns positive int when prepayment is in the future (e.g. `paidThroughDate = today + 5 days` → `5`).
- Returns 0 the day of expiration.
- Returns negative int when prepayment has lapsed (`paidThroughDate = today - 2 days` → `-2`).
- Time-of-day independence: `now = today 23:59:59`, `paidThroughDate = today 00:00:00` → `0` (not `-1`).

Use `MockClock` fixed at `2025-06-15 12:00:00 UTC` per CLAUDE.md.

#### Integration — `tests/Integration/Controller/Portal/User/OrderDetailControllerTest.php`

Extend (or create — check file existence; if absent, mirror an existing pattern such as `OrderListControllerTest`):

Three assertions, one per state, all using fixture data (no dynamic creation):

- **Free contract fixture** (added in spec 025: one user with `individualMonthlyAmount = 0`) → response body contains `Pronájem zdarma` AND `Tato smlouva nepodléhá platbám.`.
- **Externally-prepaid, > 7 days remaining fixture** (spec 025 fixture: `paidThroughDate = MockClock::today() + 5 days`) — wait, that fixture is **5 days**, which falls in the ≤7 amber band. We need both bands covered.
  - Add (or use) a fixture with `paidThroughDate = MockClock::today() + 30 days`. Assert blue banner: contains `Předplaceno externě do 15.07.2025` (or whatever the date math yields with the fixed clock) AND `Po tomto datu se obnoví běžné měsíční platby.`
  - Use the existing 5-day fixture for the amber band: contains `Externí předplatné brzy končí.` AND `simek@fajnesklady.cz`.
- **Vanilla recurring contract** (any standard fixture, e.g. `tenant@example.com`'s order) → response body does **not** contain `Pronájem zdarma`, `Předplaceno externě`, or `Externí předplatné brzy končí`.

#### Integration — `tests/Integration/Controller/Public/OrderStatusControllerTest.php`

Same three assertions, against the public `/objednavka/{id}/stav?_hash=…` permalink. Build the signed URL via the existing `OrderStatusUrlGenerator` (already used in tests for spec 020).

If a fixture for the > 7-day externally-prepaid case doesn't exist yet, add one to `OrderFixtures` / `ContractFixtures` mirroring the existing 5-day fixture from spec 025 (different user, e.g. a new "prepaid_long@example.com" or attach to `landlord2@example.com`'s tenant set — pick whatever doesn't collide with existing assertions).

### 6. PROJECT_MAP.md update

Append to the Entities row for `Contract`: mention the new `daysUntilExternalPrepaymentEnds()` helper. No route / command / event changes.

## Acceptance

- [ ] `docker compose exec web composer quality` is green (cs:fix, phpstan level 8, test:unit, test).
- [ ] `Contract::daysUntilExternalPrepaymentEnds()` returns null for non-externally-prepaid contracts (no paidThroughDate, or has GoPay token, or terminated). Unit-tested.
- [ ] **Free contract** — visiting `/portal/objednavky/{freeContractOrderId}` as `user@example.com` (or whichever fixture) renders the green "Pronájem zdarma" badge with the explanatory note. The same page rendered for the public `/stav` permalink shows the same badge. Verified by integration test.
- [ ] **External prepayment, > 7 days** — both surfaces render the blue "Předplaceno externě do {date}" banner, with the date matching `Contract.paidThroughDate` formatted `dd.mm.yyyy`.
- [ ] **External prepayment, 0..7 days** — both surfaces render the amber "Externí předplatné brzy končí" banner with the contact e-mail link.
- [ ] **Lapsed external prepayment** (`paidThroughDate < today`) — banner renders nothing. Customer sees the standard page. (The contract is now flagged in admin Po splatnosti via spec 023 — the customer-side conversion flow is deferred.)
- [ ] **Vanilla recurring contract** — neither customer surface contains any of the three new strings (`Pronájem zdarma`, `Předplaceno externě`, `Externí předplatné brzy končí`). Regression test.
- [ ] Admin order list, admin order detail, landlord order detail — **unchanged**. The shared partial is included only by the two customer surfaces; the admin onboarding banner from spec 025 stays as-is.
- [ ] Manual walk-through (with `db:reset`):
  - Login as the user owning the free fixture contract → `/portal/objednavky/{id}` shows green badge.
  - Open the `/stav` permalink for the same order in an incognito window → same badge.
  - Repeat for both prepaid fixtures (long-future, 5-days-out).
- [ ] PROJECT_MAP.md and BACKLOG.md updated.

## Out of scope

- **E-mail reminders.** Already handled by spec 025's `app:send-external-prepayment-ending-soon` cron + `SendExternalPrepaymentEndingSoonEmailHandler`. This spec is the in-app counterpart to that e-mail, not a replacement.
- **Customer self-service to convert from external prepayment to GoPay.** Deferred to spec 026 (or a future spec — currently not in BACKLOG). The amber banner directs the customer to e-mail the admin; that's the correct workflow today.
- **Editing / changing the pricing model from the customer portal.** Customers cannot self-promote / self-demote between standard / individual / free. This stays admin-only.
- **Changing the GoPay flow.** No GoPay calls, no payment-gateway changes.
- **Admin and landlord views.** They already have the spec 025 banner (`templates/admin/order/_onboarding_banner.html.twig`) and badges. We do not duplicate the customer wording for staff — the staff view is more detailed by design.
- **A "lapsed prepayment" customer-facing CTA.** When `paidThroughDate < today` and the customer hasn't converted, the banner hides itself; the contract is in the admin Po splatnosti queue (spec 023) and admins handle outreach. We don't yet have a customer-side "Smlouva v prodlení — uhraďte" UX; that's its own spec.
- **Forbidding the "free + paidThroughDate" combination at the form layer.** Spec 025 allows it; this spec only fixes the display order (free wins). Form-level mutex is a separate spec if the user wants to enforce it.
- **Localizing the banner to other languages.** Czech-only, full diacritics per memory rule.

## Open questions

None — proceed.

(The proposed wording — "Pronájem zdarma" / "Předplaceno externě do dd.mm.yyyy" / "Externí předplatné brzy končí" — is the user's stated proposal in the prompt and is consistent with the spec 025 admin-banner wording. If the user wants a different label after seeing it on the dev box, the partial is one file to tweak.)
