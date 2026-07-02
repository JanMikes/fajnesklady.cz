# 078 — Pay the whole order upfront by bank transfer, for any duration

**Status:** ready
**Type:** feature
**Scope:** medium (~16 src/template files + tests, no migration)
**Depends on:** spec 076 (must land first — this spec is written against the post-076 payment matrix)

## Problem

Post-076, a bank-transfer customer renting ≥ 31 days is forced onto the MANUAL_RECURRING track: a payment request e-mail + QR every month (or year). There is no way to simply pay the entire rental in one bank transfer. The business wants that option: whole-amount-upfront stays the default and only behavior for < 31 days (already true — `BillingMode::ONE_TIME`), and becomes an *opt-in* for any longer fixed-term rental. Card (GoPay) is explicitly excluded — GoPay exists only for automatic recurring payments (076 hard rule).

## Goal

The "Frekvence platby" section of the order form (and admin onboarding) gains a third option, **Jednorázová platba předem (celá částka)**, available whenever the rental is ≥ 31 days and the payment method is bank transfer. Choosing it produces a `BillingMode::ONE_TIME` order whose `firstPaymentPrice` is the **whole rental total** (sum of the monthly schedule, no discount): one QR payment, FIO reconciliation, contract with no billing dates, unit blocked `[start, endDate]` only (no availability guarantee — that stays a card-only perk). Payment matrix after this spec:

| Duration `d` | GoPay card | Bank transfer |
|---|---|---|
| 7 ≤ d < 31 | not offered | ONE_TIME (forced, as 076) |
| d ≥ 31 | AUTO_RECURRING monthly | MANUAL_RECURRING monthly **or** ONE_TIME whole upfront |
| d ≥ 360 | (yearly/upfront not payable by card) | MANUAL monthly **or** YEARLY (−10 %) **or** ONE_TIME whole upfront |

## Context (current state)

All verified 2026-07-02 against the working tree (076 partially implemented there — the pieces below already exist):

- `src/Enum/PaymentFrequency.php` — MONTHLY / YEARLY, `values()`, `label()`. Stored as string on `Order.paymentFrequency` (`src/Entity/Order.php:173` area) and `Contract.paymentFrequency`; **a new enum case needs no migration**.
- `src/Enum/BillingMode.php:22-35` — `derive(PaymentMethod, PaymentFrequency, int $rentalDays)`, the 076 single source of truth. `ONE_TIME` case already exists (short bank rentals).
- `src/Service/PriceCalculator.php` — `WEEKLY_THRESHOLD_DAYS = 31` (`:17`), `YEARLY_THRESHOLD_DAYS = 360` (`:19`); `calculateFirstPaymentPrice()` `:162-171` returns `buildPaymentSchedule(...)->firstPayment()->amount` — **so a single-entry upfront schedule automatically makes `firstPaymentPrice` = whole total**; `resolveRateType()` `:176-196`; `buildPaymentSchedule()` `:237-311` (yearly branch, weekly `<31d` branch, monthly walk via `walkMonthsFromAnchor()` `:396` with prorated tail); `buildScheduleFromOrder()` `:322-382` (locked-price display schedule — its `days < 31` branch returns single-entry non-recurring; **the monthly branch treats `firstPaymentPrice` as the monthly rate**, which would break for upfront orders without a new branch).
- `src/Entity/Order.php` — `billingMode` non-nullable, default `AUTO_RECURRING` (`:102`), locked via `setBillingMode()` (`:429`) from `OrderAcceptController.php:392` (public) / `AdminOnboardingHandler.php:65` (onboarding). **`Order::isRecurring()` `:340-347` is days-based** (≥ 31 ⇒ true) — a 6-month upfront order would falsely render "Měsíční platba TOTAL Kč / měsíc". Consumers of `order.isRecurring`: `templates/portal/user/order/detail.html.twig:180-181`, `portal/landlord/order/detail.html.twig:158-159`, `portal/dashboard_{user,landlord,admin}.html.twig`, `portal/user/view.html.twig:252`, `portal/{user,landlord}/order/list.html.twig`, `src/Service/Order/OrderStatusViewModelFactory.php:61`, `src/Controller/Public/CustomerSigningController.php:210-220`, `src/Event/SendOrderPlacedEmailHandler.php:74`, `SendOrderCancelledEmailHandler.php:50`, `SendOnboardingPaymentReminderEmailHandler.php:78`. Re-keying `isRecurring()` fixes all of them at once.
- **Whole downstream bank path keys on `firstPaymentPrice` and needs zero changes**: QR/payment page `src/Controller/Public/OrderPaymentController.php:111-119` (`effectivePaymentAmount = firstPaymentPrice` minus accumulated partials), FIO matching + partial accumulation + amount-mismatch flagging `src/Command/ProcessIncomingBankTransactionHandler.php:370-397`, invoice on payment (`from_total_with_vat`). Order expiration for unpaid orders: existing `Place.orderExpirationDays` cron.
- `src/Command/CompleteOrderHandler.php:31-59` — seeds `nextBillingDate` only for AUTO/MANUAL; ONE_TIME contracts get none. `Contract::getBillingCadenceStep()` (`Contract.php:409-413`) and `getEffectiveRecurringAmount()` (`:431-436`) treat non-YEARLY as monthly — correct if 077 later converts an upfront contract to MANUAL_RECURRING for extension cycles (extension pricing reads `getEffectiveMonthlyAmount()` `:364-373` = storage rates / individual override, **not** `firstPaymentPrice` — verified safe).
- Public form: `src/Form/OrderFormData.php` — `validatePaymentMethod()` `:236-256` (076 card rules), `validatePaymentFrequency()` `:258-275` (yearly ≥ 360), `deriveBillingMode()` `:283-292`, session round-trip `:327-388` (uses `tryFrom` — new case survives automatically). `src/Form/OrderFormType.php:169-180` static 2-choice `paymentFrequency`; PRE_SET_DATA listener precedent at `:195` (re-adds `endDate` with recomputed options). `src/Twig/Components/OrderForm.php` — `isCardEligible()` `:215-227`, `isEligibleForFrequencyChoice()` `:234-246` (≥ 360d), `getApplicableRate()` `:251-276`, `getPaymentSchedule()` `:297-315`. Template `templates/components/OrderForm.html.twig` — `isYearly` var `:12`, frequency section `:247-267` (hidden + `setRendered` when ineligible), payment-method section `:269-320` (guarantee box, surcharge box), schedule preview `:326-370` (`not schedule.isRecurring` branch `:348-353` already renders "Jednorázová platba: X Kč").
- Admin onboarding: `src/Form/AdminOnboardingFormData.php` — `validateYearlyHasNoCustomPrice()` `:195-210`, `validatePaymentMethod()` `:212-232`, `validatePaymentFrequency()` `:234-251`, `deriveBillingMode()` `:258-271`. `AdminOnboardingFormType.php:152-158` (frequency field area). `src/Twig/Components/AdminOnboardingForm.php:264-269` already passes `$data->paymentFrequency` into `buildPaymentSchedule` — preview works automatically. Template `templates/components/AdminOnboardingForm.html.twig` — `isYearly` `:5-6`, `form_row(form.paymentFrequency)` `:305`.
- **Latent bug this spec fixes en route**: `OrderAcceptController.php:122,310,454` and `OrderPaymentController.php:111` call `buildPaymentSchedule()` **without the frequency argument** (defaults MONTHLY) — already wrong for YEARLY display, acutely wrong for upfront (would preview a monthly schedule for an order the customer chose to pay at once).
- `OrderService::createOrder()` — yearly-vs-custom-price hard guard `:70`, `firstPaymentPrice = $monthlyPriceOverride ?? calculateFirstPaymentPrice(...)` `:114-115` (**an override would replace the upfront total with a monthly figure — must be blocked for ONE_TIME frequency, mirroring the yearly rule**).
- MRR/YRR predicates (`ContractRepository`) include only `billingMode IN (auto_recurring, manual_recurring)` — upfront contracts stay excluded by construction.
- Compliance: `.claude/COMPLIANCE.md` billing-mode matrix is being rewritten by 076; this spec adds a row and must update it **in the same commit** (its own rule).

## Architecture

```
Frekvence platby (radio, section visible when days ≥ 31)
  ├─ Měsíčně (default)             → derive() per 076 (AUTO for card / MANUAL for bank)
  ├─ Ročně −10 % (only ≥ 360d)     → MANUAL_RECURRING, bank-only (076)
  └─ Jednorázově celá částka (NEW) → BillingMode::ONE_TIME, bank-only
                                      buildPaymentSchedule(ONE_TIME) = ONE entry at startDate,
                                      amount = Σ monthly walk (tier by duration, prorated tail)
                                      → firstPaymentPrice = whole total
                                      → QR page / FIO matching / invoice: unchanged plumbing
                                      → contract: no nextBillingDate, no paidThroughDate,
                                        blocks [start, endDate] only, terminates at endDate,
                                        30/7/1 reminders + 077 prolongation (converts to MANUAL)
```

## Requirements

### 1. `PaymentFrequency::ONE_TIME`

`src/Enum/PaymentFrequency.php`:

```php
case MONTHLY = 'monthly';
case YEARLY = 'yearly';
case ONE_TIME = 'one_time';   // whole rental paid upfront in a single bank transfer (spec 078)

public function label(): string
{
    return match ($this) {
        self::MONTHLY => 'Měsíční platba',
        self::YEARLY => 'Roční platba (jednou ročně)',
        self::ONE_TIME => 'Jednorázová platba předem (celá částka)',
    };
}
```

The value `'one_time'` deliberately matches `BillingMode::ONE_TIME->value`. No migration — string-backed enum columns.

### 2. `BillingMode::derive()` — upfront wins first

`src/Enum/BillingMode.php`, top of `derive()` (before the YEARLY check):

```php
if (PaymentFrequency::ONE_TIME === $frequency) {
    return self::ONE_TIME;      // whole amount upfront; bank-only, enforced by form validation
}
```

Extend the derive unit-test matrix (BANK+ONE_TIME at 20/45/400 days → ONE_TIME; GOPAY/EXTERNAL+ONE_TIME → ONE_TIME too — validation is the gate, derive stays total).

### 3. `PriceCalculator` — upfront schedule + locked-order branch

`buildPaymentSchedule()` (`:237`): insert a ONE_TIME branch **after** the `days <= 0` and `< WEEKLY_THRESHOLD_DAYS` guards (short rentals keep weekly pricing exactly as today — ONE_TIME frequency with < 31 days falls through to the existing weekly branch; put the branch right before the final monthly-walk return):

```php
if (PaymentFrequency::ONE_TIME === $frequency) {
    $entries = $this->walkMonthsFromAnchor($monthlyRate, $startDate, $endDate);
    $total = array_sum(array_map(static fn (PaymentScheduleEntry $e) => $e->amount, $entries));

    return new PaymentSchedule(
        entries: [new PaymentScheduleEntry($startDate, $total)],
        isRecurring: false,
        isOpenEnded: false,
        monthlyAmount: $monthlyRate,   // kept for the "X Kč / měsíc" equivalence note
    );
}
```

`$monthlyRate` is the already-resolved duration tier (short vs long-term) — the upfront total is the *exact* sum the MANUAL monthly track would collect. No discount (user default decision).

`calculateFirstPaymentPrice()` needs no change — single entry ⇒ `firstPaymentPrice` = total.

`buildScheduleFromOrder()` (`:322`): add, before the days-based branches (right after the yearly block):

```php
if (PaymentFrequency::ONE_TIME === $order->paymentFrequency) {
    return new PaymentSchedule(
        entries: [new PaymentScheduleEntry($order->startDate, $order->firstPaymentPrice)],
        isRecurring: false,
        isOpenEnded: false,
        monthlyAmount: null,
    );
}
```

`resolveRateType()` needs no change (ONE_TIME falls through to the days-based tier — that is the rate the sum was built from).

### 4. `Order::isRecurring()` — key on frequency

`src/Entity/Order.php:340`:

```php
public function isRecurring(): bool
{
    if (PaymentFrequency::ONE_TIME === $this->paymentFrequency) {
        return false;   // whole amount paid upfront (spec 078)
    }

    if (null === $this->endDate) {
        return true;
    }

    return (int) $this->startDate->diff($this->endDate)->days >= PriceCalculator::WEEKLY_THRESHOLD_DAYS;
}
```

Frequency is locked at creation (`CreateOrderCommand`), so this is correct pre- *and* post-acceptance — unlike `billingMode`, which stays default until `/prijmout`. This single change fixes every `order.isRecurring` display surface listed in Context ("Celková cena X Kč" instead of "Měsíční platba X Kč / měsíc"), the `/stav` view model, the signing page, and the three e-mail handlers. Update the `isRecurring()` docblock (it claims to mirror `needsRecurringBilling()` — no longer exactly true) and the pinning unit test.

### 5. Public order form

`src/Form/OrderFormData.php`:
- `validatePaymentMethod()` (`:236`) — add alongside the yearly rule:

```php
if (PaymentMethod::GOPAY === $this->paymentMethod && PaymentFrequency::ONE_TIME === $this->paymentFrequency) {
    $context->buildViolation('Jednorázovou platbu celé částky lze provést pouze bankovním převodem.')
        ->atPath('paymentFrequency')
        ->addViolation();
}
```

- `validatePaymentFrequency()` — no new rule needed: ONE_TIME with < 31 days is semantically what already happens (derive returns ONE_TIME regardless), so a stale session value can never produce a wrong order.
- `deriveBillingMode()`, session round-trip — no changes (`tryFrom('one_time')` just works; add a session round-trip test case).

`src/Form/OrderFormType.php` (`:169-180`): keep the static field, and in the **existing** PRE_SET_DATA listener (`:195`, the endDate re-add precedent) also re-add `paymentFrequency` with date-dependent choices when both dates are set:

```php
$choices = [PaymentFrequency::MONTHLY->label() => PaymentFrequency::MONTHLY];
if ($days >= PriceCalculator::YEARLY_THRESHOLD_DAYS) {
    $choices[PaymentFrequency::YEARLY->label()] = PaymentFrequency::YEARLY;
}
if ($days >= PriceCalculator::WEEKLY_THRESHOLD_DAYS) {
    $choices[PaymentFrequency::ONE_TIME->label()] = PaymentFrequency::ONE_TIME;
}
```

The Live Component re-instantiates the form on every render, so the radios appear/disappear reactively (31–359d → Měsíčně + Jednorázově; ≥ 360d → all three). Update the field `help` text: `'Roční platba = jedna platba předem na celý rok se slevou 10 %. Jednorázová platba = celý pronájem předem jedním převodem. Obě lze platit pouze bankovním převodem.'`

`src/Twig/Components/OrderForm.php`:
- New `isEligibleForUpfrontChoice(): bool` — same shape as `isEligibleForFrequencyChoice()` (`:234`) with `WEEKLY_THRESHOLD_DAYS`.
- `getPaymentSchedule()` (`:297-315`) — extend the frequency resolution:

```php
$frequency = PaymentFrequency::MONTHLY;
if (PaymentFrequency::YEARLY === $data->paymentFrequency && $this->isEligibleForFrequencyChoice()) {
    $frequency = PaymentFrequency::YEARLY;
} elseif (PaymentFrequency::ONE_TIME === $data->paymentFrequency && $this->isEligibleForUpfrontChoice()) {
    $frequency = PaymentFrequency::ONE_TIME;
}
```

- `getApplicableRate()` — unchanged (upfront uses the days-based monthly tier; the Ceník collapse already shows it).

`templates/components/OrderForm.html.twig`:
- Line 12: add `{% set isOneTimeUpfront = form.vars.data.paymentFrequency is defined and form.vars.data.paymentFrequency is not null and form.vars.data.paymentFrequency.value == 'one_time' %}` (mirror `isYearly`).
- Frequency section gate (`:247`): `{% if this.isEligibleForFrequencyChoice() or this.isEligibleForUpfrontChoice() %}`. The `−10 %` badge in the heading (`:254`) becomes conditional on `this.isEligibleForFrequencyChoice()`. Keep the yearly explainer `<p>` gated the same way; add a sibling explainer: `Jednorázovou platbu předem lze provést pouze bankovním převodem.`
- Payment-method section: when `isOneTimeUpfront`, render an info note (blue box, mirroring the `isYearly` one at `:312-319`): `**Celý pronájem zaplatíte předem jedním bankovním převodem.** Žádné další platby v průběhu pronájmu.` Card radio: when `isOneTimeUpfront`, the server violation from req 5 is the backstop; additionally render the card radio visually disabled (same progressive-enhancement pattern 076 uses for short rentals).
- Schedule preview (`:348-353`, the `not schedule.isRecurring` branch): update the stale `SHORT (< 31 dní)` comment — this branch now also serves upfront orders — and when `schedule.monthlyAmount` is not null append a small equivalence note under the total: `celý pronájem předem — odpovídá {{ schedule.monthlyAmountInCzk|number_format(0, ',', ' ') }} Kč / měsíc`.

### 6. Admin onboarding — mirror, plus two guards

`src/Form/AdminOnboardingFormData.php`:
- `validatePaymentMethod()` (`:212`) — same GOPAY+ONE_TIME violation as req 5, **plus**:

```php
if (PaymentMethod::EXTERNAL === $this->paymentMethod && PaymentFrequency::ONE_TIME === $this->paymentFrequency) {
    $context->buildViolation('Pro pronájem uhrazený mimo systém použijte „Externí předplatné" s datem „Předplaceno do". Jednorázová platba předem je určena pro bankovní převod.')
        ->atPath('paymentFrequency')
        ->addViolation();
}
```

(Free/prepaid coercion forces EXTERNAL, so an operator combining free/prepaid with the upfront radio gets this violation and switches to Měsíčně — acceptable.)
- `validateYearlyHasNoCustomPrice()` (`:195`) — extend the condition to `in_array($this->paymentFrequency, [PaymentFrequency::YEARLY, PaymentFrequency::ONE_TIME], true)`, message widened: `'Individuální cena není u roční ani jednorázové platby podporována — cena se řídí ceníkem skladu. Zvolte standardní cenu, nebo přepněte na měsíční platbu.'` Rename the method to `validateNonMonthlyHasNoCustomPrice()`.

`src/Service/OrderService.php:70` — widen the matching hard guard to also throw for `ONE_TIME` frequency with a non-zero `$monthlyPriceOverride` (otherwise `:114` would silently store a monthly figure as the "whole total").

`src/Form/AdminOnboardingFormType.php` (`:152-158` area): same PRE_SET_DATA dynamic-choices treatment as req 5 (admin form has the same listener pattern available; if it lacks one, add it mirroring `OrderFormType`).

`templates/components/AdminOnboardingForm.html.twig`: add `isOneTimeUpfront` var next to `isYearly` (`:5-6`); near the frequency row (`:305`) add the upfront info note (`Celou částku za pronájem zákazník zaplatí předem jedním bankovním převodem.`). The schedule preview needs nothing — `AdminOnboardingForm::getPaymentSchedule()` already passes the raw frequency (`:264-269`).

### 7. Pass frequency at the four schedule call sites (fixes latent yearly bug too)

- `src/Controller/Public/OrderAcceptController.php:122, :310, :454` — `buildPaymentSchedule($storage, ..., $formData->resolvedPaymentFrequency())`.
- `src/Controller/Public/OrderPaymentController.php:111` — `buildPaymentSchedule($storage, $order->startDate, $order->endDate, $order->paymentFrequency ?? PaymentFrequency::MONTHLY)`.

The recurring-consent gate (`OrderAcceptController.php:284`) already requires `AUTO_RECURRING === resolvedBillingMode()` — upfront orders derive ONE_TIME, so no consent card renders on `/prijmout` (verify in the integration test). `CustomerSigningController.php:210-220` needs no code change — re-keyed `Order::isRecurring()` makes `showRecurringConsent`/`showManualInfo` false and the schedule `null` for upfront onboardings; just audit that `customer_signing.html.twig` renders the one-time price block sensibly (it already does for short ONE_TIME).

### 8. Fixtures + docs

- `fixtures/`: add one paid upfront pair — e.g. `REF_ORDER_COMPLETED_UPFRONT` (bank transfer, `paymentFrequency: ONE_TIME`, `billingMode: ONE_TIME`, ~4-month span around the MockClock date, `firstPaymentPrice` = summed total, VS assigned) + matching contract (no `nextBillingDate`, no `paidThroughDate`). Document in `.claude/FIXTURES.md`.
- `.claude/COMPLIANCE.md` (same commit): in the 076-rewritten matrix add the upfront row — *Jednorázová platba předem: bankovní převod, libovolná délka ≥ 31 dní, celá částka = součet měsíčních plateb (bez slevy), žádné opakované platby, bez garance dostupnosti*. Yearly remains the only discounted prepay.
- Note for spec 077 (no file change needed there): its ONE_TIME→MANUAL_RECURRING prolongation conversion works unchanged for upfront contracts (`getBillingCadenceStep()` treats non-YEARLY as `+1 month`; extension amounts come from storage rates, not `firstPaymentPrice`). If the implementer of 077 wants belt-and-braces, `applyPaymentFrequency(MONTHLY)` at conversion is a one-liner.

### 9. Tests

- Unit: `BillingMode::derive()` ONE_TIME rows; `Order::isRecurring()` with ONE_TIME frequency (and the docblock-pinning test update); `PriceCalculator` upfront schedule — single entry, total = sum of the monthly walk (assert equality against a MONTHLY-frequency schedule for the same window), tier boundary cases (45d → short tier, 200d → long tier, prorated tail included), `< 31d + ONE_TIME` falls to weekly branch; `buildScheduleFromOrder()` upfront branch (never treats total as monthly rate).
- Form validation: GOPAY+ONE_TIME → violation; bank+ONE_TIME 45d → `billingMode = ONE_TIME` derived; admin EXTERNAL+ONE_TIME → violation; admin custom price + ONE_TIME → violation; session round-trip preserves `one_time`.
- Integration: order flow — bank + upfront + 3-month window → `/prijmout` shows single total, **no recurring consent checkbox**, submit → payment page QR = whole total; FIO transaction with matching VS+amount completes the order and the contract has `billingMode = ONE_TIME`, `nextBillingDate = null`. Frequency radio matrix rendering (hidden < 31d, two options at 45d, three at 400d).
- `composer quality` green AND full `composer test` (controller + template churn ⇒ integration tests must run).

## Acceptance

- [ ] Bank transfer + 3-month rental: "Frekvence platby" shows Měsíčně / Jednorázová platba předem; choosing upfront previews one payment equal to the sum of the monthly schedule and `/prijmout` + QR page show the same single total.
- [ ] Bank transfer + 400-day rental: all three options; yearly keeps the −10 % badge; upfront total = monthly-walk sum (no discount).
- [ ] < 31 days: no frequency section, behavior identical to 076 (forced ONE_TIME).
- [ ] Card + upfront selected → inline violation `Jednorázovou platbu celé částky lze provést pouze bankovním převodem.`; card radio visually disabled while upfront is selected.
- [ ] Paid upfront order renders "Celková cena X Kč" (no "/ měsíc") on user/landlord/admin detail + lists, dashboards, `/stav`, and order e-mails.
- [ ] Upfront contract: no `nextBillingDate`, receives no manual-billing payment requests, not counted in MRR/YRR, blocks its unit only `[start, endDate]`, gets 30/7/1 expiration reminders.
- [ ] Admin onboarding: same option for BANK_TRANSFER; EXTERNAL+upfront and custom-price+upfront rejected with the specified messages.
- [ ] `.claude/COMPLIANCE.md` + `.claude/FIXTURES.md` updated in the same commit; no DB migration present.
- [ ] `composer quality` green and full `composer test` green.

## Out of scope

- **Discount for paying upfront** — defaulted to none (yearly −10 % stays the only discounted prepay); computed upfront discount can be a follow-up.
- **Charging the bank-transfer surcharge** — it is display-only today (rendered from `PlatformSettings`, never added to any amount); this spec doesn't change that either way.
- **Availability guarantee for upfront** — deliberate 076 product decision: guarantee is card-only; upfront customers' units are theirs for the paid window but not beyond.
- **EXTERNAL upfront** — admins record outside payments via "Externí předplatné / Předplaceno do"; blocked combination, not a second path.
- **Refund/proration when an upfront rental terminates early** — pre-existing gap shared with yearly (tracked conceptually by spec 019's settle-on-cancel work), unchanged.
- **Spec 077 changes** — prolongation of upfront contracts already works via its ONE_TIME→MANUAL conversion; only an optional one-line hardening noted in req 8.

## Open questions

None blocking — proceed. Three decisions were defaulted while the user was away (each easy to veto before implementation):
1. Upfront price = plain sum of the monthly schedule, **no discount**.
2. ≥ 360 days shows **all three** frequency options (upfront included, even though yearly is cheaper for exactly 12 months — upfront serves e.g. 18-months-at-once).
3. Admin onboarding **mirrors** the option (BANK_TRANSFER only).
