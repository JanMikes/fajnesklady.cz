## 045 — Yearly pricing tier (rate + always-manual yearly cadence)

**Status:** done
**Type:** feature
**Scope:** large (~25 files: 1 entity field on StorageType/Storage/Contract, PriceCalculator branch, OrderForm Live Component, 5 forms, manual-billing cron, 8 customer-facing partials/emails, migration, fixtures, tests)
**Depends on:** spec 036 (manual recurring infrastructure) — reused verbatim, only the cadence anchor changes.

## Problem

The shop today exposes two rates per storage type: **týdenní** (charged when the rental is < 28 days) and **měsíční** (charged for everything ≥ 28 days, UNLIMITED included). The operator wants a third tier — **roční** — to incentivise customers to commit for a year or longer. Today every yearlong contract is silently billed as 12 separate monthly charges at the monthly rate; there is no way to offer a discount in exchange for an upfront yearly commitment.

A naive "yearly recurring GoPay charge" runs straight into the legal `MAX_RECURRING_PAYMENT_AMOUNT_IN_HALER = 1 500 000` cap (Podmínky opakovaných plateb čl. III, 15 000 Kč per recurring charge): 12 × any storage above ~1 250 Kč/měs breaches it. Re-issuing the Podmínky PDF + 7-business-day customer notice is a separate compliance project.

## Goal

1. Operator can set a **per-year price** on each StorageType (and override per Storage), or leave it blank for the legacy "yearly = monthly × 12, no discount" behaviour.
2. When the customer opens the order form and the rental is eligible (UNLIMITED, or LIMITED ≥ ~12 months), they see an extra radio **Frekvence platby: Měsíční / Roční** alongside the existing AUTO-vs-MANUAL choice from spec 036. Picking Roční switches the rate and reveals an explanatory "platba jednou ročně, bez ukládání karty" line.
3. Yearly cadence is **always MANUAL** — the customer's first payment is a one-shot GoPay; subsequent yearly charges arrive as the same manual-payment-request e-mail used by `MANUAL_RECURRING` today (spec 036), just anchored at `+1 year` instead of `+1 month`. This sidesteps the GoPay 15k cap entirely; no Podmínky re-issuance is required.
4. Customer-facing surfaces (order recap, order detail, status page, post-payment e-mails) **always lead with the equivalent monthly figure** ("1 250 Kč / měsíc") with a small grey "(účtováno jednou ročně, 15 000 Kč)" annotation. Direct restatement of the user brief: *"I want to see how much it will cost me per month, not per year."*
5. Both admin onboarding flows (digital + migrate-paper) gain the same frequency selector with the same eligibility rules.

## Context (current state)

**Pricing model — what already exists**

- `src/Entity/StorageType.php:62-64`: two integer columns `defaultPricePerWeek`, `defaultPricePerMonth` (halere). Hellpers `getDefaultPricePerWeekInCzk()` / `getDefaultPricePerMonthInCzk()`. Updated via `updateDetails(...)` (line 149).
- `src/Entity/Storage.php:28-31`: nullable overrides `pricePerWeek` / `pricePerMonth`. `getEffectivePricePerWeek()` (line 164) / `getEffectivePricePerMonth()` (line 169) read `?? $this->storageType->default…`. `hasCustomPrices()` (line 184) checks either.
- `src/Service/PriceCalculator.php`: cutover at `WEEKLY_THRESHOLD_DAYS = 28`. `calculatePrice()`, `calculatePriceForStorage()`, `buildPaymentSchedule()`, `buildScheduleFromOrder()`, `getPriceBreakdown()` all branch on this single threshold.
- `src/Twig/Components/OrderForm.php:138-159` (`getApplicableRate()`) returns `'weekly' | 'monthly' | null` — drives the Ceník collapse from spec 040.
- `templates/components/OrderForm.html.twig:450-484`: Ceník panel with the two-row collapse + fallback explainer.

**Frequency model — what already exists**

- `src/Enum/PaymentFrequency.php` has both cases (`MONTHLY` + `YEARLY`); only `MONTHLY` is referenced anywhere in production code (3 hard-coded call-sites: `OrderAcceptController.php:301`, `AdminCreateOnboardingHandler.php:62`, `AdminMigrateCustomerHandler.php:61`).
- `src/Entity/Order.php:169-170`: nullable column `paymentFrequency` (enum) — already wired through the constructor.
- `src/Entity/Contract.php`: **no** `paymentFrequency` column today — must be added so the billing cron knows how to advance `nextBillingDate`.
- `src/Service/OrderService.php:53`: `createOrder(..., ?PaymentFrequency $paymentFrequency = null, ...)` — signature already accepts it; it's set on the Order but never consulted again.

**Billing-mode infrastructure (spec 036)**

- `src/Enum/BillingMode.php`: `ONE_TIME` / `AUTO_RECURRING` / `MANUAL_RECURRING`.
- `src/Entity/Contract.php:99-100`: `billingMode` column (default `auto_recurring`), mirrored from Order at completion by `OrderService::completeOrder()`.
- `src/Console/SendManualBillingPaymentRequestsCommand.php`: cron that walks every `MANUAL_RECURRING` contract and dispatches `DispatchManualBillingNotificationCommand` per the per-Place reminder schedule (`ManualBillingReminderSchedule`).
- `src/Command/DispatchManualBillingNotificationHandler.php`: creates `ManualPaymentRequest` rows, issues GoPay one-shots, emails customer; on confirmed payment the webhook branch in `ProcessPaymentNotificationHandler` calls `Contract::recordBillingCharge()`.
- `src/Command/ChargeRecurringPaymentHandler.php:177`: advances `nextBillingDate` by `+1 month` — currently hardcoded.
- `src/Service/Billing/ManualBillingReminderSchedule.php`: 5-stage schedule (`initial` / `d_minus_2` / `d_zero` / `d_plus_3` / `d_plus_7`) snapshot onto each Order from Place defaults. Reused unchanged for yearly cadence — the same "remind early, chase late" rhythm applies whether the anchor is one month away or one year away.

**Repository scoping**

`src/Repository/ContractRepository.php` has both legacy `where('c.goPayParentPaymentId IS NOT NULL')` clauses (lines 332, 366, 400 — for AUTO billing cron) and the post-spec-036 `andWhere('c.billingMode IN (:recurringModes)')` predicate (lines 784+ for MRR / dashboards). Yearly contracts will live in `MANUAL_RECURRING` and **are not** AUTO — they are correctly excluded from the AUTO cron by the `goPayParentPaymentId IS NOT NULL` clause (no token saved). They are correctly included in the spec-036 manual cron because they have `billingMode = manual_recurring`.

**Compliance**

- `public/documents/podminky-opakovanych-plateb.pdf` and `App\Service\PriceCalculator::MAX_RECURRING_PAYMENT_AMOUNT_IN_HALER` describe **GoPay ON_DEMAND recurring** charges only. A yearly one-shot GoPay is a normal payment, not a recurring charge — the cap does not apply, the Podmínky PDF does not need re-issuance, and the recurring-payment consent checkbox is **not** shown to customers who pick yearly cadence. See `.claude/COMPLIANCE.md`.
- VOP wording: yearly = "platba předem na celý rok". No new disclosure obligation under § 1826a OZ (no auto-renewal). Update the on-page modal sentence to mention the option exists; the dynamic VOP DOCX (spec 035) does not need yearly-specific placeholders.

## Architecture

```
StorageType.defaultPricePerYear  (nullable int, halere)
        └── Storage.pricePerYear  (nullable int override)
                └── Storage::getEffectivePricePerYear()  (fallback: monthly × 12 when null)

Order
  ├── paymentFrequency  (existing column, now actually used: MONTHLY | YEARLY)
  ├── billingMode       (spec 036)
  └── firstPaymentPrice (existing: the locked-in monthly OR locked-in yearly amount)

Contract
  ├── paymentFrequency  (NEW column, mirrors Order at completion)
  ├── billingMode       (spec 036)
  └── nextBillingDate   (advanced by +1 month or +1 year on each charge)

Eligibility matrix (drives which radios appear in the order form):

  rentalType   | days        | frequency options | billingMode options
  -------------|-------------|-------------------|----------------------
  LIMITED      | < 28        | (none — forced)   | ONE_TIME
  LIMITED      | 28..359     | MONTHLY only      | AUTO | MANUAL
  LIMITED      | ≥ 360       | MONTHLY | YEARLY  | AUTO | MANUAL (MONTHLY only)
                                                   forced MANUAL (YEARLY)
  UNLIMITED    | n/a         | MONTHLY | YEARLY  | AUTO | MANUAL (MONTHLY only)
                                                   forced MANUAL (YEARLY)
```

`YEARLY_THRESHOLD_DAYS = 360` (the loose "≥ ~12 months" cutoff the operator chose — survives leap-day edge cases; a 360-day rental will get a tiny tail prorated at the yearly daily rate or a 5-day overshoot at no extra charge depending on which side of the math you sit; cheaper and clearer than chasing exact calendar months).

## Requirements

### 1. New columns + migration

**`src/Entity/StorageType.php`** — add fourth price field (mirror weekly/monthly exactly):

```php
#[ORM\Column(nullable: true)]
public private(set) ?int $defaultPricePerYear = null;
```

Constructor: append `?int $defaultPricePerYear = null` (after `defaultPricePerMonth`). `updateDetails(...)` gains the same parameter. New helper `getDefaultPricePerYearInCzk(): ?float`.

**`src/Entity/Storage.php`** — add nullable override:

```php
#[ORM\Column(nullable: true)]
public private(set) ?int $pricePerYear = null;
```

Modify `updatePrices(...)` to a 3-arg signature `(?int $pricePerWeek, ?int $pricePerMonth, ?int $pricePerYear, \DateTimeImmutable $now)`. Add helpers:

```php
public function getEffectivePricePerYear(): int
{
    return $this->pricePerYear
        ?? $this->storageType->defaultPricePerYear
        ?? $this->storageType->defaultPricePerMonth * 12;  // fallback: no discount
}

public function getEffectivePricePerYearInCzk(): float
{
    return $this->getEffectivePricePerYear() / 100;
}
```

Extend `hasCustomPrices()` to OR-in `pricePerYear !== null`.

**`src/Entity/Contract.php`** — add mirror column:

```php
#[ORM\Column(length: 20, enumType: PaymentFrequency::class, options: ['default' => 'monthly'])]
public private(set) PaymentFrequency $paymentFrequency = PaymentFrequency::MONTHLY;
```

Constructor: accept it as a constructor arg with `MONTHLY` default. Modify `recordBillingCharge(...)` to advance the anchor by `+1 year` when `paymentFrequency === YEARLY`, otherwise `+1 month` (the cron in `ChargeRecurringPaymentHandler` will keep computing the anchor itself; the entity itself doesn't choose the step — see §6).

**Migration (generate via `bin/console make:migration`, do NOT handwrite)**

After editing the three entities, run `docker compose exec web bin/console make:migration`. Confirm the generated diff adds:

- `storage_type.default_price_per_year INT NULL`
- `storage.price_per_year INT NULL`
- `contract.payment_frequency VARCHAR(20) NOT NULL DEFAULT 'monthly'`

Backfill `contract.payment_frequency = 'monthly'` is handled by the DEFAULT; no explicit `UPDATE` needed.

### 2. `PaymentFrequency` enum — add Czech label

`src/Enum/PaymentFrequency.php` — add a label helper (mirrors `BillingMode::label()`):

```php
public function label(): string
{
    return match ($this) {
        self::MONTHLY => 'Měsíční platba',
        self::YEARLY => 'Roční platba (jednou ročně)',
    };
}
```

### 3. `PriceCalculator` — yearly branch

Add `public const int YEARLY_THRESHOLD_DAYS = 360;`. New helper:

```php
/**
 * Pick the rate that applies for the given duration + customer-chosen frequency.
 *
 * - frequency=YEARLY  → 'yearly' (eligibility validated at form layer)
 * - frequency=MONTHLY + days < 28 → 'weekly' (one-shot)
 * - frequency=MONTHLY + days ≥ 28 → 'monthly'
 * - frequency=MONTHLY + UNLIMITED → 'monthly'
 *
 * @return 'weekly'|'monthly'|'yearly'
 */
public function resolveRateType(
    PaymentFrequency $frequency,
    \DateTimeImmutable $startDate,
    ?\DateTimeImmutable $endDate,
): string
```

Extend `calculatePriceForStorage()` and `buildPaymentSchedule()` to branch on `PaymentFrequency` (signatures gain a third `PaymentFrequency $frequency` parameter; default to `MONTHLY` so legacy call-sites that pass nothing keep working — but `OrderService` MUST start passing the order's actual frequency through).

`buildPaymentSchedule()` yearly branch:

- **LIMITED ≥ 360 days, YEARLY**: walk `+1 year` from `startDate`; on overshoot, prorate the tail at the yearly daily rate (`amount = round_up(remainingDays * yearlyRate / 365)`). Mirror the existing `walkMonthsFromAnchor` proration logic exactly.
- **UNLIMITED, YEARLY**: single open-ended entry at `(startDate, yearlyRate)`; subsequent yearly charges added by the manual-billing cron after each successful payment.

`buildScheduleFromOrder()` reads `$order->paymentFrequency` to pick the branch, anchoring on `firstPaymentPrice` (which is now the yearly amount for YEARLY orders).

`needsRecurringBilling(...)` stays unchanged — yearly is recurring (just manual). The yearly threshold does NOT change this method (a 6-month rental remains monthly recurring).

### 4. Forms — admin pricing inputs

**`src/Form/StorageTypeFormData.php`** — new property:

```php
#[Assert\Type('numeric')]
#[Assert\PositiveOrZero]
public ?float $defaultPricePerYear = null;
```

Populate in `fromStorageType()` from `getDefaultPricePerYearInCzk()`.

**`src/Form/StorageTypeFormType.php`** — new field after `defaultPricePerMonth`:

```php
$builder->add('defaultPricePerYear', NumberType::class, [
    'label' => 'Výchozí cena za rok (CZK)',
    'required' => false,
    'scale' => 2,
    'attr' => [
        'placeholder' => 'Nepovinné — pokud nevyplníte, roční sazba = měsíční × 12',
        'step' => '0.01',
    ],
    'help' => 'Pokud nastavíte, zákazníci s pronájmem na 12 a více měsíců dostanou volbu „Roční platba". Účtováno jednou ročně, bez ukládání karty.',
]);
```

`StorageTypeCreateController.php` + `StorageTypeEditController.php`: convert `$formData->defaultPricePerYear` to halere (or `null`), pass to constructor / `updateDetails()`.

**`src/Form/StorageFormData.php`** + **`StorageFormType.php`** — mirror the existing nullable-override pattern for `pricePerWeek` / `pricePerMonth`. Field only built when `!$storageType->uniformStorages` (matches existing `if` block at `StorageFormType.php:104`).

```php
$builder->add('pricePerYear', NumberType::class, [
    'label' => 'Vlastní cena za rok (CZK)',
    'required' => false,
    'scale' => 2,
    'attr' => [
        'placeholder' => 'Použije se výchozí cena typu',
        'step' => '0.01',
    ],
    'help' => 'Nechte prázdné pro použití výchozí roční ceny typu skladu',
]);
```

`UpdateStorageCommand` + `UpdateStorageHandler` propagate the new field into `Storage::updatePrices(...)`.

### 5. Order form — frequency selector

**`src/Form/OrderFormData.php`** — add property + cross-field validator:

```php
/**
 * Customer-chosen payment frequency. Locked at order creation, mirrors onto
 * Contract at completion, drives the manual-billing cron cadence anchor
 * (+1 month vs +1 year) and the customer-facing "X Kč / měsíc" annotation.
 *
 * Forced MONTHLY when ineligible (LIMITED < 360 days). Defaults to MONTHLY
 * when not yet selected.
 */
#[Assert\NotNull]
public PaymentFrequency $paymentFrequency = PaymentFrequency::MONTHLY;
```

Cross-field rule in the existing `validate()` `Callback`:

```php
// YEARLY is only eligible for UNLIMITED or LIMITED ≥ 360 days
if (PaymentFrequency::YEARLY === $this->paymentFrequency
    && RentalType::LIMITED === $this->rentalType
    && null !== $this->startDate
    && null !== $this->endDate
    && (int) $this->startDate->diff($this->endDate)->days < PriceCalculator::YEARLY_THRESHOLD_DAYS
) {
    $context->buildViolation('Roční platba je dostupná pouze pro pronájem na 12 měsíců a déle.')
        ->atPath('paymentFrequency')
        ->addViolation();
}

// YEARLY forces MANUAL billing (sidesteps GoPay 15k recurring cap)
if (PaymentFrequency::YEARLY === $this->paymentFrequency
    && BillingMode::MANUAL_RECURRING !== $this->billingMode
) {
    // Auto-correct silently — the form layer prevents the customer from
    // ever reaching this state via disabled radios. This is a defence-in-depth
    // guard against tampered form submissions.
    $this->billingMode = BillingMode::MANUAL_RECURRING;
}
```

Extend `toLiveProps()` / `fromLiveProps()` to round-trip `paymentFrequency->value`.

**`src/Form/OrderFormType.php`** — new `EnumType` field, only built when eligible (read `$options['storage_type']` + cross-check the threshold). Pattern: same `->add('billingMode', EnumType::class, …)` block at line 185, with `choices` set to `[PaymentFrequency::MONTHLY->label() => PaymentFrequency::MONTHLY, PaymentFrequency::YEARLY->label() => PaymentFrequency::YEARLY]`. Use `expanded: true, multiple: false` (radio buttons).

**`src/Twig/Components/OrderForm.php`** — extend:

```php
public function isEligibleForFrequencyChoice(): bool
{
    $data = $this->getForm()->getData();
    if (!$data instanceof OrderFormData) {
        return false;
    }
    if (null === $this->storage->getEffectivePricePerYear()) {
        return false;  // unreachable today (fallback always returns int), but defensive
    }

    if (RentalType::UNLIMITED === $data->rentalType) {
        return true;
    }
    if (null === $data->startDate || null === $data->endDate) {
        return false;
    }

    return (int) $data->startDate->diff($data->endDate)->days >= PriceCalculator::YEARLY_THRESHOLD_DAYS;
}
```

`getApplicableRate()` (line 138) — extend return type to `'weekly'|'monthly'|'yearly'|null`:

```php
if (PaymentFrequency::YEARLY === $data->paymentFrequency
    && $this->isEligibleForFrequencyChoice()
) {
    return 'yearly';
}
// …existing weekly/monthly branches unchanged
```

`getPaymentSchedule()` — pass `$data->paymentFrequency` through to `PriceCalculator::buildPaymentSchedule()`.

New view-helper for the recap "monthly equivalent of yearly":

```php
public function getYearlyMonthlyEquivalentInCzk(): float
{
    return $this->storage->getEffectivePricePerYear() / 12 / 100;
}
```

### 6. Templates — Ceník panel + frequency radio + recap

**`templates/components/OrderForm.html.twig`** lines 450-484 (the Ceník panel) — extend to a 3-row applicable-rate collapse:

```twig
{% set applicableRate = this.applicableRate %}
<div class="space-y-2">
    <h3 class="font-semibold text-gray-900">Ceník</h3>

    {% if applicableRate is null or applicableRate == 'weekly' %}
        <div class="flex justify-between text-sm">
            <span class="text-gray-600">Týdenní sazba{% if applicableRate == 'weekly' %} <span class="ml-1 text-xs font-medium text-accent">(platí pro vás)</span>{% endif %}</span>
            <span class="font-semibold {{ applicableRate == 'weekly' ? 'text-accent' : 'text-gray-900' }}">{{ weeklyPrice|number_format(0, ',', ' ') }} Kč <span class="text-xs text-gray-500 font-normal">vč. DPH</span></span>
        </div>
    {% endif %}

    {% if applicableRate is null or applicableRate == 'monthly' %}
        <div class="flex justify-between text-sm">
            <span class="text-gray-600">Měsíční sazba{% if applicableRate == 'monthly' %} <span class="ml-1 text-xs font-medium text-accent">(platí pro vás)</span>{% endif %}</span>
            <span class="font-semibold {{ applicableRate == 'monthly' ? 'text-accent' : 'text-gray-900' }}">{{ monthlyPrice|number_format(0, ',', ' ') }} Kč <span class="text-xs text-gray-500 font-normal">vč. DPH</span></span>
        </div>
    {% endif %}

    {% if this.eligibleForFrequencyChoice and (applicableRate is null or applicableRate == 'yearly') %}
        <div class="flex justify-between text-sm">
            <span class="text-gray-600">Roční sazba{% if applicableRate == 'yearly' %} <span class="ml-1 text-xs font-medium text-accent">(platí pro vás)</span>{% endif %}</span>
            <span class="font-semibold {{ applicableRate == 'yearly' ? 'text-accent' : 'text-gray-900' }}">
                {{ storage.effectivePricePerYearInCzk|number_format(0, ',', ' ') }} Kč
                <span class="text-xs text-gray-500 font-normal block">≈ {{ this.yearlyMonthlyEquivalentInCzk|number_format(0, ',', ' ') }} Kč / měsíc</span>
            </span>
        </div>
    {% endif %}

    {% if applicableRate is null %}
        <p class="text-xs text-gray-500 mt-2 bg-gray-50 p-2 rounded">
            Pronájem kratší než 4 týdny se účtuje týdenní sazbou,
            delší pronájem měsíční sazbou (výhodnější).
            {% if this.eligibleForFrequencyChoice %}Pronájem na 12 měsíců a déle můžete platit ročně předem.{% endif %}
        </p>
    {% endif %}
</div>
```

**Frequency radio** — render alongside the existing `billingMode` radio (also expanded), with a label + helper text:

```twig
{% if this.eligibleForFrequencyChoice %}
    <div class="mt-4">
        <h4 class="font-semibold text-gray-900 mb-2">Frekvence platby</h4>
        {{ form_widget(form.paymentFrequency, { attr: { class: 'space-y-2' } }) }}
        <p class="mt-2 text-xs text-gray-500">
            Roční platba znamená jednu platbu předem na celý rok.
            Karta se neukládá — další platbu obdržíte výzvou e-mailem před vypršením roku.
        </p>
    </div>
{% endif %}
```

When `paymentFrequency === YEARLY`, the existing `billingMode` radio is **disabled and pre-set to MANUAL_RECURRING** (no AUTO option) — drop the AUTO radio button via a Twig `{% if data.paymentFrequency != 'yearly' %}` guard around it, with an explanatory line "Roční platba se vždy odbavuje ručně (bez ukládání karty)."

**Recap "schedule" block** (lines 277-300) — when `schedule.isYearly()` is true, the per-row labels should read e.g. *"Platba na rok 1: 1. 6. 2026 — 15 000 Kč"* and the headline pricing line becomes:

```twig
<span class="text-lg font-bold text-accent">
    {{ this.yearlyMonthlyEquivalentInCzk|number_format(0, ',', ' ') }} Kč / měsíc
    <span class="text-xs text-gray-500 font-normal block">účtováno jednou ročně — {{ schedule.yearlyAmountInCzk|number_format(0, ',', ' ') }} Kč</span>
</span>
```

Add `PaymentSchedule::isYearly(): bool` + `PaymentSchedule::yearlyAmountInCzk: float` (or expose via `yearlyAmount: int` halere with helper). Compute as `monthlyAmount * 12` is wrong — the schedule must carry the actual yearly amount it was built with. Extend the value object's constructor to accept a `?int $yearlyAmount = null` and a `bool $isYearly = false` flag (mutually exclusive with `monthlyAmount`/recurring? — keep both; readers branch on `isYearly()`).

### 7. `OrderService::createOrder()` — pass frequency through

`OrderService::createOrder()` already accepts `?PaymentFrequency $paymentFrequency = null`. Make it **required-with-default**: change default to `PaymentFrequency::MONTHLY` and stop accepting `null`. Use it when computing `$firstPaymentPrice`:

```php
$firstPaymentPrice = $monthlyPriceOverride
    ?? $this->priceCalculator->calculateFirstPaymentPrice($storage, $startDate, $endDate, $paymentFrequency);
```

`PriceCalculator::calculateFirstPaymentPrice()` gains the same parameter and returns the yearly amount when `frequency = YEARLY` (i.e. `$storage->getEffectivePricePerYear()` for UNLIMITED or LIMITED ≥ 360d; falls back to monthly otherwise).

**`OrderService::completeOrder()`** — pass `$order->paymentFrequency` into the `new Contract(...)` constructor so it survives onto the contract.

### 8. Three consumer entry-points — pass `paymentFrequency`

Today three call-sites hard-code `PaymentFrequency::MONTHLY`:

- `src/Controller/Public/OrderAcceptController.php:301` — read from `$orderFormData->paymentFrequency` instead.
- `src/Command/AdminCreateOnboardingHandler.php:62` — read from new `$command->paymentFrequency` field.
- `src/Command/AdminMigrateCustomerHandler.php:61` — same.

### 9. Admin onboarding forms

**`AdminCreateOnboardingFormData.php`** + **`AdminMigrateCustomerFormData.php`** — add `public PaymentFrequency $paymentFrequency = PaymentFrequency::MONTHLY;` with the same eligibility validator as `OrderFormData`.

**`AdminCreateOnboardingFormType.php`** + **`AdminMigrateCustomerFormType.php`** — add the `paymentFrequency` `EnumType` field in the same "Cenový model" section (spec 025). Display only when the chosen StorageType has a `defaultPricePerYear` set (otherwise yearly is meaningless — no point letting the admin pick "yearly" with no rate to bill).

**`AdminCreateOnboardingHandler.php`** + **`AdminMigrateCustomerHandler.php`** — pass it to `OrderService::createOrder(...)` (in `AdminMigrate`, also to the directly-constructed `Contract` for migrate-paper).

### 10. Manual-billing cron — anchor cadence on frequency

**`src/Command/ChargeRecurringPaymentHandler.php`** (the AUTO cron) — unchanged. AUTO_RECURRING is never YEARLY by construction (the form layer forces YEARLY → MANUAL_RECURRING); the AUTO predicate `goPayParentPaymentId IS NOT NULL` correctly excludes manual-yearly contracts (no token saved).

**`src/Command/DispatchManualBillingNotificationHandler.php`** — the per-stage handler that records `recordBillingCharge` after a successful one-shot. Today it calls `$contract->nextBillingDate->modify('+1 month')` (or equivalent — verify exact location). Change to read `$contract->paymentFrequency` and modify by `+1 year` when YEARLY.

In fact the cleanest place to put this is **on the Contract entity itself**, so every charge path (AUTO + MANUAL + future manual reconciliation in `ProcessPaymentNotificationHandler`) shares one rule:

```php
// Contract.php
public function getBillingCadenceStep(): string
{
    return PaymentFrequency::YEARLY === $this->paymentFrequency ? '+1 year' : '+1 month';
}
```

Then `ChargeRecurringPaymentHandler.php:177` becomes `$billingPeriodStart->modify($contract->getBillingCadenceStep())` and the equivalent line in the manual reconciliation path the same.

**`src/Console/SendManualBillingPaymentRequestsCommand.php`** — no change. The candidate query already filters by `billingMode = manual_recurring` and `nextBillingDate <= now + window`. The reminder schedule is anchored on `nextBillingDate`, which we just made cadence-aware. Same 5 reminder offsets (D-7 / D-2 / D-0 / D+3 / D+7) apply equally to yearly cadence — a 7-day pre-charge ping for "your yearly payment is due in a week" is the right rhythm.

### 11. Customer-facing displays — yearly annotation

**`templates/components/_price_label.html.twig`** + **`templates/email/_price_label.html.twig`** — the partials standardised in spec 021. Today they accept `monthlyAmount` and render "X Kč / měsíc". Extend with optional `yearlyAmount` arg:

```twig
{# Always lead with monthly equivalent — direct user brief.  #}
<span class="font-semibold">{{ (yearlyAmount is defined and yearlyAmount > 0 ? (yearlyAmount / 12) : monthlyAmount)|number_format(0, ',', ' ') }} Kč / měsíc</span>
{% if yearlyAmount is defined and yearlyAmount > 0 %}
    <span class="text-xs text-gray-500 block">účtováno jednou ročně, {{ yearlyAmount|number_format(0, ',', ' ') }} Kč</span>
{% endif %}
```

Every call-site of `_price_label.html.twig` (audit grep) that has access to a `Contract` or `Order` adds `yearlyAmount = order.paymentFrequency.value == 'yearly' ? order.firstPaymentPrice : null` to the include args.

**`templates/components/customer_billing_status.html.twig`** (spec 030) — gains a fourth variant **"Roční platba"** (purple/teal banner) shown when `contract.paymentFrequency === YEARLY`: *"Platíte ročně. Další platba: dd. mm. yyyy."* Wins over MONTHLY but loses to free / prepaid (already documented hierarchy).

**`templates/public/order_status.html.twig`** + **`templates/portal/user/order/detail.html.twig`** — pick up the partial changes automatically.

### 12. Recurring-payment legal disclosure — skip for yearly

The dedicated recurring-payment consent checkbox at `templates/public/order_accept.html.twig` (spec 016) — wrap its containing block in `{% if order.paymentFrequency.value != 'yearly' %}`. Yearly is a normal one-shot purchase (no token saved, no ON_DEMAND), so no per-Podmínky-čl.-III consent is required. The on-page modal text already covers "VOP / Podmínky opakovaných plateb" generally; no wording change needed.

The `findRequiringAdvanceNotice()` query in `ContractRepository.php:356` filters by `goPayParentPaymentId IS NOT NULL` — yearly contracts are correctly excluded (no token saved). Same for `findDueForBilling()` and `findNeedingRetry()`. **No changes to these queries** — yearly lives entirely in the manual cron path.

### 13. Fixtures

`src/DataFixtures/StorageTypeFixtures.php` — set `defaultPricePerYear` on at least the first two seeded types to a 10–15% discount on `monthlyPrice × 12` so dev can exercise the feature end-to-end. Document the convention in the constants:

```php
public const int REF_PLZEN_LARGE_PRICE_PER_YEAR = 12_000_00;  // 1 000 Kč/měs × 12 with 16% discount
```

`tests/Integration/.../ContractFixtures.php` (if it asserts the `paymentFrequency` field) — leave the default `MONTHLY` for existing fixtures; add one explicit yearly contract for the recurring-yearly tests.

### 14. Tests

- **Unit**: `PriceCalculatorTest` gains a `resolveRateType_*` matrix and a yearly `buildPaymentSchedule_*` case (LIMITED ≥ 360d + LIMITED < 360d + UNLIMITED, with and without an explicit `defaultPricePerYear`). Also test the `monthly × 12` fallback when StorageType has no yearly price.
- **Unit**: `OrderFormDataTest` — validation rejects YEARLY for LIMITED < 360 days; auto-corrects YEARLY + AUTO_RECURRING → MANUAL_RECURRING.
- **Integration**: `OrderCreationTest` (or the existing equivalent) — creating a YEARLY UNLIMITED order persists `paymentFrequency = YEARLY` on both Order and Contract, sets `firstPaymentPrice` to the yearly amount, and creates a `ManualPaymentRequest` for the first charge.
- **Integration**: `ChargeRecurringPaymentHandlerTest` / `DispatchManualBillingNotificationHandlerTest` — yearly contracts advance `nextBillingDate` by `+1 year` after a successful charge; AUTO cron skips yearly contracts entirely.
- **Integration**: smoke-test that the order-accept page does NOT render the recurring-payment consent block for YEARLY orders.

### 15. PROJECT_MAP

Append a "Yearly pricing tier" subsection under the pricing/billing area:

```
Yearly pricing tier (spec 044):
- StorageType.defaultPricePerYear (nullable), Storage.pricePerYear override
- Storage::getEffectivePricePerYear() — falls back to monthly × 12 when unset
- Contract.paymentFrequency mirrors Order.paymentFrequency
- Contract::getBillingCadenceStep() → '+1 month' | '+1 year' (read by both billing crons)
- YEARLY is always BillingMode::MANUAL_RECURRING (form-layer constraint + entity-level defence)
- PriceCalculator::YEARLY_THRESHOLD_DAYS = 360 (LIMITED eligibility)
- Customer-facing: always shows "X Kč / měsíc (účtováno jednou ročně, Y Kč)"
- GoPay 15 000 Kč ON_DEMAND cap does not apply (yearly = one-shot, no token)
```

### 16. COMPLIANCE addendum

Append a short paragraph to `.claude/COMPLIANCE.md` under "Recurring payments" stating that **yearly cadence is implemented as one-shot annual GoPay payments, NOT as ON_DEMAND recurring**, and therefore (a) the 15 000 Kč per-charge cap from Podmínky čl. III does not apply, (b) the dedicated recurring-payment consent checkbox is suppressed for YEARLY orders, (c) no Podmínky PDF re-issuance is required when yearly is enabled per place.

## Acceptance

- [ ] `docker compose exec web composer quality` is green.
- [ ] `docker compose exec web composer test` is green (full integration suite — feature has new repo queries + new cron paths).
- [ ] `docker compose exec web bin/console doctrine:schema:validate` clean after migration applied.
- [ ] StorageType admin form shows a "Výchozí cena za rok (CZK)" input below the monthly input; saving empty leaves `defaultPricePerYear = NULL`, saving a positive number persists in halere.
- [ ] Storage admin form (only when `!uniformStorages`) shows the matching per-storage override.
- [ ] Public order form (`templates/components/OrderForm.html.twig`):
  - [ ] Setting LIMITED endDate to 1 year + 1 day from startDate reveals the "Frekvence platby" radio with both options.
  - [ ] Setting LIMITED endDate to 6 months keeps the form on MONTHLY only — no yearly radio appears.
  - [ ] Setting RentalType=UNLIMITED reveals the radio immediately.
  - [ ] Picking YEARLY hides the BillingMode (AUTO/MANUAL) radio and shows "Roční platba se vždy odbavuje ručně".
  - [ ] The Ceník panel collapses to a single "Roční sazba: 15 000 Kč ≈ 1 250 Kč / měsíc" row when YEARLY is picked.
  - [ ] Recap headline shows "1 250 Kč / měsíc — účtováno jednou ročně, 15 000 Kč".
- [ ] Admin onboarding flows (digital + migrate) show the same selector with the same eligibility, only when the chosen storage type has a `defaultPricePerYear`.
- [ ] Creating a YEARLY UNLIMITED order through the public flow:
  - [ ] Persists `Order.paymentFrequency = YEARLY`, `Order.firstPaymentPrice = yearly amount`.
  - [ ] After payment + contract creation, `Contract.paymentFrequency = YEARLY` and `Contract.billingMode = MANUAL_RECURRING`.
  - [ ] No GoPay token is saved on the contract (`Contract.goPayParentPaymentId IS NULL`).
  - [ ] `Contract.nextBillingDate` = startDate + 1 year, `paidThroughDate` = startDate + 1 year.
- [ ] Manual cron `app:send-manual-billing-payment-requests`:
  - [ ] Picks up yearly contracts at D-7 from `nextBillingDate` (initial reminder) and sends a one-shot GoPay link.
  - [ ] After successful yearly payment via webhook reconciliation, `Contract.nextBillingDate` advances by exactly `+1 year` (not `+1 month`).
- [ ] AUTO cron `app:bill-recurring-payments` (whatever it's called) does NOT pick up yearly contracts.
- [ ] Customer-facing surfaces (order detail / `/stav` page / post-payment e-mail) all show "X Kč / měsíc" as the primary number and "(účtováno jednou ročně, Y Kč)" in small grey beneath.
- [ ] Recurring-payment consent checkbox on `/prijmout` is hidden for YEARLY orders; standard VOP consent still shown.
- [ ] Existing monthly orders + monthly contracts are unaffected (`paymentFrequency` backfills to MONTHLY via DEFAULT; all existing tests still pass).

## Out of scope

- **Switching frequency on an existing contract** — admins cannot toggle a monthly contract into yearly mid-life (same constraint as billingMode in spec 036). Locked at order creation. Operator workflow: terminate + re-onboard.
- **Discount-percentage helper** in the admin form — operator types the yearly amount directly; we don't compute "10% off monthly × 12" suggestions.
- **Yearly billing on existing weekly tier** — weekly rate stays for LIMITED < 28 days, no yearly option for short rentals (obviously).
- **VOP DOCX template change** — the dynamic VOP (spec 035) DOCX has no placeholder for "your billing frequency". The yearly customer's VOP reads the same as monthly; the cadence is implicit in the payment schedule. If the operator wants explicit yearly wording in the VOP, that's a separate spec.
- **Podmínky opakovaných plateb wording change** — yearly is not an ON_DEMAND recurring charge; the existing PDF text remains correct and untouched.
- **MRR projection in admin dashboards** — yearly contracts contribute `yearlyAmount / 12` to MRR. This is a one-line change in `ContractRepository::loadCustomerStatsByUserIds()` (and friends), but a separate spec because it ripples across spec-026 charts. **Track as a follow-up.**
- **Excel exports (spec 028)** — adding "Frekvence platby" / "Roční amount" columns to admin/landlord lists. Cosmetic; defer.
- **Calendar / "Časová osa" planning views (spec 027)** — they project monthly cadence; yearly contracts will simply show one charge per year on the timeline. No special UI per spec.

## Open questions

None — proceed.
