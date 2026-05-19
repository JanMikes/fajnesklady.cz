# 042 — Hide lifetime total from the order-form schedule panel (last customer-facing surface that still sums recurring charges)

**Status:** done
**Type:** UX / wording rule
**Scope:** tiny (~3 files: 1 Twig template, 1 unit test, 1 manual walk-through)
**Depends on:** none. Closes the gap left open by spec [021](021-customer-price-display.md) for the Live Component `OrderForm` schedule panel.

## Problem

Spec 021 set the codebase-wide rule "customer never sees a lifetime sum across recurring charges" and converted every customer-facing surface to the cadence-aware partial — except one: the order-form Ceník/schedule panel inside the `OrderForm` Live Component. For a fixed-term ≥ 28-day rental, that panel still renders:

```
Cena celkem (6 platby)        31 600 Kč  vč. DPH
1. platba (15.06.2026)         5 000 Kč
2. platba (15.07.2026)         5 000 Kč
…
doplatek (10.12.2026)          1 600 Kč
```

The bold "31 600 Kč" header is exactly the lifetime sum spec 021 says we must not show — and it sits on the very first page the customer hits when ordering, so it's the most prominent violation of the rule.

The per-payment breakdown beneath is fine and explicitly wanted ("payments distribution showing what all payments will happen … is awesome"). It informs the customer of cadence + dates + the prorated tail without ever presenting a summed figure.

## Goal

In the fixed-term ≥ 28-day branch of `templates/components/OrderForm.html.twig`, replace the "Cena celkem (N platby): TOTAL Kč" header with the same "Měsíční platba: X Kč / měsíc" header used by the unlimited branch. Keep the per-payment `<ul>` exactly as it is. After this change, no customer-facing surface in the repository renders a lifetime sum across recurring charges.

## Context (current state)

### The one surface still violating the rule

`templates/components/OrderForm.html.twig:285-300` — the fixed-end ≥ 28-day branch of the schedule panel:

```twig
{% else %}
    {# FIXED-END ≥ 28 dní — full schedule with prorated tail #}
    <div class="flex items-center justify-between border-b border-accent/20 pb-2">
        <span class="text-sm text-gray-600">Cena celkem ({{ schedule.entryCount }} platby)</span>
        <span class="text-lg font-bold text-accent">{{ schedule.totalKnownAmountInCzk|number_format(0, ',', ' ') }} Kč <span class="text-xs text-gray-500 font-normal">vč. DPH</span></span>
    </div>
    <ul class="space-y-1 text-xs text-gray-600">
        {% for entry in schedule.entries %}
            <li class="flex items-center justify-between">
                <span>{{ loop.first ? '1. platba' : (loop.last ? 'doplatek' : (loop.index ~ '. platba')) }} <span class="text-gray-400">({{ entry.chargeDate|date('d.m.Y') }})</span></span>
                <span class="font-medium text-gray-800">{{ entry.amountInCzk|number_format(0, ',', ' ') }} Kč</span>
            </li>
        {% endfor %}
    </ul>
{% endif %}
```

The two sibling branches above already get it right:

- Unlimited (line 273): `Měsíční platba (na dobu neurčitou)` + `X Kč / měsíc`.
- One-time < 28 days (line 279): `Jednorázová platba` + total (correct here — a single charge IS the total).

### Surfaces already correct (verified — do not touch)

Audited against the user's rule "user must not see total price calculated together":

| Surface | File / line | Notes |
|---|---|---|
| Public order recap card | `templates/public/order_accept.html.twig:98-105` | Uses `_price_label.html.twig` — monthly only for recurring. |
| Public payment page | `templates/public/order_payment.html.twig:70-77, 175` | `_price_label.html.twig` + button shows first-payment only; recurring info line shows monthly rate (not sum). |
| Customer signing | `templates/public/customer_signing.html.twig:68-69` | Conditional on `isRecurring`, suffix `/ měsíc`, no sum. |
| Public status permalink | `templates/public/order_status.html.twig:127-137` | `_price_label.html.twig`. |
| Portal user order detail | `templates/portal/user/order/detail.html.twig:146-147` | Cadence-aware label + `/ měsíc` suffix, no sum. |
| Portal user order list | `templates/portal/user/order/list.html.twig:68` | Row renders `firstPaymentPrice` + `/ měsíc`, no sum. |
| Portal user dashboard | `templates/portal/dashboard_user.html.twig:193` | Same as list. |
| Email — `order_placed` | `templates/email/order_placed.html.twig:124` | `email/_price_label.html.twig` (monthly-only for recurring). |
| Email — `order_cancelled` | `templates/email/order_cancelled.html.twig:114` | Same partial. |
| Email — `rental_activated` | `templates/email/rental_activated.html.twig:160` | Only `monthlyAmount` inside `{% if isRecurring %}` block. |
| Email — external prepayment ending | `templates/email/external_prepayment_ending_soon.html.twig:101` | Monthly only. |
| Admin/landlord order detail aggregate | `templates/_price_aggregate.html.twig` | Role-gated — staff only. **Out of scope** (per spec 021). |
| Admin/landlord order list & dashboards | various | "Měsíční platba" / "/ měsíc" suffix only; no per-row sum. |
| Order-flow "Parametry opakované platby" card | `templates/public/order_accept.html.twig:439-485` | Compliance card — shows monthly + entry count + end date. `entryCount` is a count, NOT a price; do NOT remove. |

### Why one isolated change is enough

The Live Component re-renders on every `data-model="on(change)|*"` blur, so dropping the offending header from the Twig template removes the value from the page without a single PHP-side change. `PaymentSchedule::totalKnownAmount()` / `totalKnownAmountInCzk()` stay on the value object — they're still needed by the admin/landlord aggregate partial.

### Convention reminder

- Customer-facing Czech text uses full diacritics (per memory rule).
- `OrderForm` is a Symfony UX Live Component; the schedule panel mutates server-side via `getPaymentSchedule()` (`src/Twig/Components/OrderForm.php:170`) and there is no Stimulus mirror — Twig is the single source of UX truth.

## Requirements

### 1. Replace the header row in the fixed-term branch

Edit `templates/components/OrderForm.html.twig:285-300`. Replace the `{% else %}` branch body so the only visible change vs. today is the deleted header row + new monthly header (parallel to lines 273-278 unlimited branch); the per-payment `<ul>` stays byte-for-byte:

```twig
{% else %}
    {# FIXED-END ≥ 28 dní — monthly recurrence with prorated tail.
       Per spec 042 (closes spec 021's last gap): customer must never see
       a lifetime sum across recurring charges. Header shows the per-month
       rate (parallel to the unlimited branch above); the breakdown below
       shows the individual scheduled charges with dates — informational,
       not a presented total. #}
    <div class="flex items-center justify-between border-b border-accent/20 pb-2">
        <span class="text-sm text-gray-600">Měsíční platba</span>
        <span class="text-lg font-bold text-accent">{{ schedule.monthlyAmountInCzk|number_format(0, ',', ' ') }} Kč / měsíc <span class="text-xs text-gray-500 font-normal">vč. DPH</span></span>
    </div>
    <ul class="space-y-1 text-xs text-gray-600">
        {% for entry in schedule.entries %}
            <li class="flex items-center justify-between">
                <span>{{ loop.first ? '1. platba' : (loop.last ? 'doplatek' : (loop.index ~ '. platba')) }} <span class="text-gray-400">({{ entry.chargeDate|date('d.m.Y') }})</span></span>
                <span class="font-medium text-gray-800">{{ entry.amountInCzk|number_format(0, ',', ' ') }} Kč</span>
            </li>
        {% endfor %}
    </ul>
{% endif %}
```

Notes on the diff:
- The label "Měsíční platba" matches the unlimited branch literally — same wording, same visual rhythm. Customers who switch the rental-type radio see only the parenthetical change ("(na dobu neurčitou)" appears / disappears).
- `schedule.monthlyAmountInCzk` is non-null in this branch: it is only entered when `schedule.isRecurring && !schedule.isOpenEnded`, and `PriceCalculator::buildPaymentSchedule` always populates `monthlyAmount` for recurring schedules (`src/Service/PriceCalculator.php:227`).
- Do NOT touch the unlimited branch (line 273) or the one-time branch (line 279).

### 2. Twig-only test for the fixed-term schedule panel

Add a focused unit-or-integration test verifying the panel never emits `totalKnownAmountInCzk` for a fixed-term recurring schedule. Two options — pick the one matching what already exists in the repo:

**Option A — if `tests/Integration/Twig/Components/OrderFormTest.php` exists:** add a test case that renders the component for a 90-day rental (15.06.2025 → 13.09.2025) at 5 000 Kč/měsíc and asserts:
- Rendered HTML contains `Měsíční platba</span>` AND `5 000 Kč / měsíc`.
- Rendered HTML does NOT contain `Cena celkem` AND does NOT contain the substring `15 000` (the would-be lifetime sum).
- Rendered HTML contains `1. platba`, `2. platba`, `3. platba`, and `doplatek` — i.e. the breakdown ul is preserved.

**Option B — if no such test class exists:** add `tests/Unit/Twig/OrderFormSchedulePanelTest.php` that exercises the Twig fragment directly via the test container's `twig` service rendering an inline template that includes only the affected `{% if/elseif/else/endif %}` chain with a synthesised `PaymentSchedule` (90-day, 5 000 Kč). Same three assertions.

Acceptance of the test is the same in either case: it fails on the current code (because `Cena celkem` and `15 000` are still present), passes after the edit.

### 3. Manual walk-through (Czech, full diacritics)

After `docker compose exec web composer db:reset`, on the dev box:

1. Visit `/objednavka/{placeId}/{storageTypeId}` for any 5 000 Kč/měsíc storage as anonymous user.
2. Set startDate to today, click the "3 měsíce" preset (spec 039).
3. **Expect** in the schedule panel:
   - Header: "Měsíční platba …… 5 000 Kč / měsíc  vč. DPH".
   - List below: "1. platba (…) 5 000 Kč", "2. platba (…) 5 000 Kč", "doplatek (…) X Kč" (where X is the prorated tail, ≤ 5 000 Kč).
   - No occurrence of "Cena celkem" anywhere on the page.
   - No bold lifetime sum anywhere on the page.
4. Switch the rental-type radio to **Na dobu neurčitou** — header changes to "Měsíční platba (na dobu neurčitou) … 5 000 Kč / měsíc", list disappears.
5. Switch back to fixed and shrink endDate so the rental is < 28 days (e.g. 14 days) — header becomes "Jednorázová platba … 2 333 Kč" (the genuine one-shot total, which is correct per the rule).

## Acceptance

- [ ] `docker compose exec web composer quality` is green.
- [ ] Manual walk-through (req. 3) passes — no "Cena celkem" / no lifetime sum on the fixed-term branch.
- [ ] New test (req. 2) is green; same test fails when checked out at `HEAD~1`.
- [ ] Repository-wide grep `grep -rn "totalKnown\|Cena celkem\|cena celkem" templates/` returns only: `templates/_price_aggregate.html.twig` (admin/landlord aggregate, role-gated). Every other hit gone.
- [ ] `BACKLOG.md` updated: new row `042` (status `ready`).

## Out of scope

- **Removing `PaymentSchedule::totalKnownAmount()` / `totalKnownAmountInCzk()` from the value object.** Still used by `templates/_price_aggregate.html.twig` (admin / landlord "K zaplacení celkem" row, spec 021). Do NOT delete.
- **Changing the admin/landlord aggregate partial.** Staff legitimately need lifetime totals to operate the platform — spec 021 nailed this distinction. Keep it role-gated, untouched.
- **Removing `paymentSchedule.entryCount` from `templates/public/order_accept.html.twig:470` ("{{ paymentSchedule.entryCount }} platby do …").** That's a count of billing cycles, not a price; it's part of the GoPay compliance "Parametry opakované platby" card (`order_accept.html.twig:430-485`) where each row maps to a GoPay reviewer-checklist field. Touching it risks breaking the compliance gate — leave it.
- **Touching email price renderings.** Audited above; all six customer-facing emails route monthly-only via `email/_price_label.html.twig` or render `monthlyAmount` inside an `{% if isRecurring %}` block. Nothing to fix.
- **Renaming `Order.totalPrice` → `Order.firstPaymentPrice`.** Already called out as a documented wart in spec 021 ("Out of scope"). Same here.
- **Updating any tests that intentionally assert on the old "Cena celkem" string** — none exist (verified by grep), but if one is found during implementation, update it rather than skipping it.

## Open questions

None — proceed.
