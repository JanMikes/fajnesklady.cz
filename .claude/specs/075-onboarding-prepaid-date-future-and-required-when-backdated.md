# 075 — Onboarding "Předplaceno do" must be ≥ today, and required when the start is backdated

**Status:** done
**Type:** validation tweak
**Scope:** small (1 FormData + 1 FormType + 1 template + 1 component line + test updates)
**Depends on:** none (builds on the admin onboarding form, spec 050; the prepaid field is spec 025)

## Problem

On the admin onboarding form (`/portal/admin/onboarding`) the operator can record an external prepayment via the "Externí předplatné" checkbox + a "Předplaceno do" (`paidThroughDate`) date. Two gaps today:

1. **No lower bound on `paidThroughDate`.** The only date checks are `paidThroughDate ≥ startDate` and (for LIMITED) `paidThroughDate ≤ endDate`. An operator can set a prepaid-through date in the **past**, which is nonsensical — a prepayment that already lapsed is a debt, not active coverage, and it would make the "external prepayment ending soon" cron (`app:send-external-prepayment-ending-soon`) and the customer "Předplaceno externě do …" banner behave incorrectly.

2. **Backdated starts can be saved with no prepayment recorded.** Admin onboarding intentionally allows a **past `startDate`** (migrating a customer who has been renting for a while — unlike the public order form, which floors `startDate` at today). But for any paid (non-free) rental, a past start means the elapsed period must already have been covered outside GoPay. Today the form lets the admin save a backdated, non-free onboarding with `paidThroughDate` empty, leaving the billing engine with no idea when external coverage runs out / when to start charging.

## Goal

- Whenever `paidThroughDate` is provided, it must be **today or in the future**.
- When `startDate` is **in the past** and the rental is **not free**, `paidThroughDate` becomes **required**, and the form automatically treats the onboarding as externally prepaid (the "Externí předplatné" toggle is applied for the operator, with an explanation), mirroring how `billingMode` is force-applied + explained when YEARLY/BANK_TRANSFER is chosen.
- Free contracts (`monthlyPriceMode = 'free'`) are exempt — they never bill, so a prepaid-through date is meaningless.

## Context (current state)

- **Form data + validation:** `src/Form/AdminOnboardingFormData.php`.
  - `paidThroughDate` is `?\DateTimeImmutable` (line 94); `isExternallyPrepaid` is `bool` (line 92); `startDate` is `?\DateTimeImmutable` (line 72); `monthlyPriceMode` is `?string` with values `'standard' | 'custom' | 'free'` (line 86).
  - `validatePaidThroughDate()` (lines 290-319) currently early-returns unless `isExternallyPrepaid`, then requires the date and checks `≥ startDate` and `≤ endDate` (LIMITED).
  - `validateExternalIsPrepaid()` (lines 268-288) requires `paidThroughDate` for `EXTERNAL` + non-free + not-prepaid, nudging the operator to tick the checkbox.
  - **Clock pattern:** this POPO can't DI a clock; `validateBirthDate()` (line 152) already compares against `new \DateTimeImmutable('today')`. Follow that — do NOT try to inject `ClockInterface`.
- **Field config:** `src/Form/AdminOnboardingFormType.php` — `paidThroughDate` is a `DateType` single_text (lines 184-189) with only a help text. The datepicker controller supports a `data-datepicker-min-date-value` attr (see `OrderFormType.php:181`, and `assets/controllers/datepicker_controller.js:8,50`).
- **Template:** `templates/components/AdminOnboardingForm.html.twig` — section "12. Externí předplatné" (lines 326-337) renders the checkbox and conditionally the date (`{% if isExternallyPrepaid %}`). `monthlyPriceMode` and `isExternallyPrepaid` are derived at the top (lines 9-10) from `formData`. **Precedent to mirror:** section 11 (lines 297-324) hides `billingMode` via `{% do form.billingMode.setRendered %}` and shows an explanatory blue box when the mode is forced (YEARLY / BANK_TRANSFER) — we use the same hide-and-explain pattern for the auto-applied prepayment.
- **Persistence:** `src/Twig/Components/AdminOnboardingForm.php` `submit()` — line 386: `$paidThroughDate = $formData->isExternallyPrepaid ? $formData->paidThroughDate : null;`. `submit()` early-returns on invalid form (lines 354-356), so line 386 only runs once validation passes. The `paidThroughDate` flows into `AdminOnboardingCommand` (line 424). Note `isExternallyPrepaid` itself is **not** passed to the command — it's purely a form-layer gate, so no model mutation is needed; broadening this one expression is enough.
- **Scope is admin-onboarding only.** The public order flow has no prepaid concept and already floors `startDate` at today, so nothing there changes.
- **Existing tests:** `tests/Unit/Form/AdminOnboardingFormDataTest.php` (plain `TestCase` + a real Symfony validator built in `validator()`; `validData()` baseline + `violationsAt(path, data)` helper). **`validData()` sets `startDate = new \DateTimeImmutable('2025-06-15')` — a date already in the past relative to the real current date**, which is fine today (nothing compares `startDate` to `today`) but will be tripped by the new rule.

## Requirements

### 1. `AdminOnboardingFormData` — add `startsInPast()` helper

```php
public function startsInPast(): bool
{
    return null !== $this->startDate
        && $this->startDate < new \DateTimeImmutable('today');
}
```

(`startDate` from the `DateType` single_text widget is at midnight, as is `'today'`, so a start of *today* is NOT in the past.)

### 2. `AdminOnboardingFormData::validatePaidThroughDate()` — rewrite (lines 290-319)

Gate on a "collected" predicate (prepaid **or** backdated, never free), then require + range-check. The `≥ today` check is the new rule; keep the existing `≥ startDate` and `≤ endDate` checks.

```php
#[Assert\Callback]
public function validatePaidThroughDate(ExecutionContextInterface $context): void
{
    // The "Předplaceno do" date is in play when the customer prepaid externally, or
    // when the rental starts in the past (elapsed period must already be covered).
    // Never for free rentals. When not in play it is nulled at submit — don't validate
    // a stale value.
    $collected = 'free' !== $this->monthlyPriceMode
        && ($this->isExternallyPrepaid || $this->startsInPast());

    if (!$collected) {
        return;
    }

    if (null === $this->paidThroughDate) {
        $context->buildViolation($this->startsInPast()
            ? 'Datum začátku je v minulosti — zadejte datum, do kdy má zákazník předplaceno (dnes nebo v budoucnosti).'
            : 'Zadejte datum, do kdy je předplaceno.')
            ->atPath('paidThroughDate')
            ->addViolation();

        return;
    }

    // NEW: must be today or in the future.
    if ($this->paidThroughDate < new \DateTimeImmutable('today')) {
        $context->buildViolation('Datum „Předplaceno do" musí být dnes nebo v budoucnosti.')
            ->atPath('paidThroughDate')
            ->addViolation();
    }

    // Existing: cannot precede the start date.
    if (null !== $this->startDate && $this->paidThroughDate < $this->startDate) {
        $context->buildViolation('Datum předplatby nemůže být před datem začátku.')
            ->atPath('paidThroughDate')
            ->addViolation();
    }

    // Existing: for fixed-term rentals, cannot exceed the contract end.
    if (RentalType::LIMITED === $this->rentalType
        && null !== $this->endDate
        && $this->paidThroughDate > $this->endDate
    ) {
        $context->buildViolation('Datum předplatby nemůže být po datu konce smlouvy.')
            ->atPath('paidThroughDate')
            ->addViolation();
    }
}
```

### 3. `AdminOnboardingFormData::validateExternalIsPrepaid()` — avoid a duplicate message for backdated EXTERNAL

The backdated case is now owned by `validatePaidThroughDate`. Add one early-return after the free check (lines ~275) so an `EXTERNAL` + past-start onboarding doesn't fire both messages on `paidThroughDate`:

```php
if ('free' === $this->monthlyPriceMode) {
    return;
}

if ($this->startsInPast()) {
    return; // backdated → handled by validatePaidThroughDate
}
```

(The future-start EXTERNAL nudge is unchanged.)

### 4. `AdminOnboardingFormType` — client-side floor on the picker

Add a `min-date` attr to `paidThroughDate` (lines 184-189), mirroring `OrderFormType.php:181`. Server validation stays authoritative; this is UX defense.

```php
->add('paidThroughDate', DateType::class, [
    'label' => 'Předplaceno do',
    'required' => false,
    'widget' => 'single_text',
    'attr' => [
        'data-datepicker-min-date-value' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
    ],
    'help' => 'Po vypršení předplatného bude zákazníkovi 7 dní předem zaslán e-mail s žádostí o nastavení automatické platby.',
])
```

### 5. Template — auto-apply prepayment for backdated starts (section 12, lines 326-337)

When the start is in the past and the rental is not free, hide the checkbox, show an explanatory box, and render the (now-required) date — mirroring the billingMode-when-forced pattern. Otherwise keep today's behavior.

```twig
{# 12. Externí předplatné #}
<div class="card">
    <div class="card-body">
        <h2 class="text-lg font-bold text-gray-900 mb-4">Externí předplatné</h2>
        {% if formData.startsInPast and monthlyPriceMode != 'free' %}
            <div class="mb-3 bg-amber-50 border border-amber-200 rounded-lg p-4">
                <p class="text-sm text-amber-800">
                    Datum začátku je v minulosti, proto je externí předplatné automaticky zapnuto.
                    Zadejte datum, do kdy má zákazník předplaceno (musí být dnes nebo v budoucnosti).
                </p>
            </div>
            {% do form.isExternallyPrepaid.setRendered %}
            {{ form_row(form.paidThroughDate) }}
        {% else %}
            {{ form_row(form.isExternallyPrepaid) }}
            {% if isExternallyPrepaid %}
                {{ form_row(form.paidThroughDate) }}
            {% else %}
                {% do form.paidThroughDate.setRendered %}
            {% endif %}
        {% endif %}
    </div>
</div>
```

### 6. Component — persist `paidThroughDate` for backdated non-free onboardings

`src/Twig/Components/AdminOnboardingForm.php` line 386 — broaden the gate so the date persists when the start is backdated even though the checkbox was rendered-off (preserves the existing `isExternallyPrepaid` branch verbatim, including the free case):

```php
$paidThroughDate = ($formData->isExternallyPrepaid
    || ($formData->startsInPast() && 'free' !== $formData->monthlyPriceMode))
    ? $formData->paidThroughDate
    : null;
```

### 7. Tests — `tests/Unit/Form/AdminOnboardingFormDataTest.php`

**Required fixture fixes (the new `today`-relative rule trips hardcoded past dates):**
- In `validData()`, change `startDate` to `new \DateTimeImmutable('today')`. Without this, `testValidDataPassesValidation` (asserts **0** violations) fails, because the baseline's `2025-06-15` start is now backdated + non-free → `paidThroughDate` required. (This also restores `testExternalNonFreeRequiresPrepaidDate` to exercise its intended `validateExternalIsPrepaid` path rather than the backdated path.)
- In `testDebtWithExternalPaymentMethodFails` (line ~187), change `paidThroughDate` from `new \DateTimeImmutable('2026-01-01')` (now in the past → would add a stray `≥ today` violation; the test still passes because it asserts on the `paymentMethod` path, but the fixture is invalid) to `(new \DateTimeImmutable('today'))->modify('+6 months')`.
- Other tests that set their own past `startDate` (e.g. `testLimitedMinimum7Days`, line ~79) assert via `violationsAt('endDate', …)`, so the extra backdated `paidThroughDate` violation does not break them — leave them, or relativize the dates for cleanliness (optional). **Run the full file to confirm none assert on total violation counts.**

**New tests (all dates `today`-relative — never hardcoded literals, since validation uses real `today`):**
- `paidThroughDate` in the past is rejected: `isExternallyPrepaid = true`, `paidThroughDate = (new \DateTimeImmutable('today'))->modify('-1 day')` → `violationsAt('paidThroughDate')` non-empty.
- `paidThroughDate` = today passes: `isExternallyPrepaid = true`, `paidThroughDate = new \DateTimeImmutable('today')` → empty at that path.
- Backdated non-free start requires the date: `startDate = today -1 day`, `monthlyPriceMode = 'standard'`, `isExternallyPrepaid = false`, `paidThroughDate = null` → non-empty.
- Backdated **free** start does NOT require it: same but `monthlyPriceMode = 'free'` → empty.
- Backdated non-free start with a valid future date passes: `startDate = today -1 day`, `paidThroughDate = today +30 days` → empty at that path.
- (Optional) `startsInPast()` returns true for `today -1 day`, false for `today` and `today +1 day`.

## Acceptance

- [ ] Setting "Předplaceno do" to a past date is rejected with "… musí být dnes nebo v budoucnosti."
- [ ] A backdated, non-free onboarding cannot be submitted without "Předplaceno do"; the section auto-shows the explanatory box + required date and hides the checkbox (the standard prepaid behavior still applies when the start is today/future).
- [ ] A backdated **free** onboarding submits without a prepaid date.
- [ ] The saved `Contract.paidThroughDate` is populated for a backdated non-free onboarding even though the checkbox was auto-applied (verify the date reaches `AdminOnboardingCommand`).
- [ ] `composer test` is green (this touches a Live Component template + form — run the full suite, not just `composer quality`); new unit tests pass.

## Out of scope

- **Public order flow / any non-onboarding form** — `paidThroughDate` exists only on admin onboarding; the order form already floors `startDate` at today.
- **An upper bound on `paidThroughDate` for UNLIMITED rentals** — not requested; the existing `≤ endDate` (LIMITED) bound stays.
- **Backfilling / re-validating existing contracts** with past `paidThroughDate` values — this is a create-time form rule only.
- **Injecting a clock into FormData** — out of step with the existing `new \DateTimeImmutable('today')` convention in this class; would be a broader refactor.
- **Changing the `EXTERNAL` future-start nudge wording** in `validateExternalIsPrepaid` — only the backdated short-circuit is added.

## Open questions

None — proceed. (Resolved: free contracts are exempt from the backdated requirement; a backdated start is auto-treated as externally prepaid via the hide-and-explain pattern.)
