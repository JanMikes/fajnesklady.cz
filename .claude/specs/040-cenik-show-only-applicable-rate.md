# 040 — Order form Ceník: show only the rate that will actually apply

**Status:** done
**Type:** UX
**Scope:** tiny (1 PHP method, 1 Twig template section)
**Depends on:** none

## Problem

The right-hand "Shrnutí objednávky" panel on `/objednavka/{placeId}/{storageTypeId}/{storageId?}` always lists both `Týdenní sazba` and `Měsíční sazba` plus an explainer paragraph — regardless of what the customer has actually configured. By the time the form is filled out, the system already knows exactly which rate will be charged (UNLIMITED → monthly; LIMITED < 28 days → weekly; LIMITED ≥ 28 days → monthly), but the panel keeps showing both. That's noise — customers ask which one applies to them, and the irrelevant row drags the eye away from the relevant one.

## Goal

Once the customer's selections lock in the applicable rate, the Ceník panel collapses to that single row and drops the now-redundant "kratší než 4 týdny…" explainer. While selections are still undecided (default state — LIMITED with no dates), keep showing both rows with the explainer so customers can comparison-shop while picking dates. The panel updates reactively the same way the existing dynamic payment-schedule block already does — no client-side math.

## Context (current state)

- Live Component class: `src/Twig/Components/OrderForm.php`
  - Injects `PriceCalculator` (line 62)
  - Already exposes `getPaymentSchedule(Storage): ?PaymentSchedule` (line 133) and `isEligibleForBillingModeChoice(): bool` (line 106) — same pattern we'll mirror.
  - Reads form via `getForm()->getData()` → `OrderFormData` (`src/Form/OrderFormData.php:71-76`: `rentalType` defaults to `RentalType::LIMITED`, `startDate` / `endDate` default to `null`).
- Threshold constant: `PriceCalculator::WEEKLY_THRESHOLD_DAYS = 28` (`src/Service/PriceCalculator.php:15`). Reuse — don't re-derive.
- Cutover logic to mirror (already in `PriceCalculator::buildPaymentSchedule`):
  - `endDate === null` (UNLIMITED) → monthly applies.
  - `days < 28` → weekly applies.
  - `days >= 28` → monthly applies.
  - `days <= 0` (invalid range, e.g. end ≤ start) → treat as undecided.
- Twig section to modify: `templates/components/OrderForm.html.twig:441-455` — the `Ceník` block (h3 + two `flex justify-between` rows + the gray-50 explainer `<p>`).
- Top of the template (lines 4-5) already pulls `weeklyPrice` / `monthlyPrice` from `selectedStorage`. Keep those — we still need both values for the undecided state.
- Live UX reactivity is already wired: every input has `data-action="blur->live#action" data-live-action-param="validateField"`, which fires a re-render on blur. The existing dynamic-pricing block (lines 257-292) demonstrates that server-rendered conditionals respond to form changes correctly. No new wiring needed.

Gotchas:
- `rentalIsLimited` (template line 10) reads `form.vars.data.rentalType` directly with a `null OR == 'limited'` fallback. Don't duplicate that logic in the new method — read the live form data via `getForm()->getData()` (an `OrderFormData` instance, same pattern as `getPaymentSchedule`).
- Czech UI text must keep full diacritics (project convention).
- This change is the customer-facing order-create flow but does NOT alter pricing, validation, or compliance copy. The pre-order disclaimer block and submit button stay untouched.

## Requirements

### 1. Add `getApplicableRate()` to the Live Component

File: `src/Twig/Components/OrderForm.php`

Add a single read-only method next to `getPaymentSchedule()`:

```php
/**
 * Which storage rate will actually be charged given the customer's current
 * selections. Mirrors {@see PriceCalculator::buildPaymentSchedule()} cutover:
 *
 *   - UNLIMITED                          → 'monthly'
 *   - LIMITED, days < 28                 → 'weekly'
 *   - LIMITED, days >= 28                → 'monthly'
 *   - LIMITED, dates missing or invalid  → null (undecided — show both)
 *
 * The Ceník sidebar collapses to the single applicable row when this
 * returns a string, and falls back to "both rates + explainer" on null.
 *
 * @return 'weekly'|'monthly'|null
 */
public function getApplicableRate(): ?string
{
    $data = $this->getForm()->getData();
    if (!$data instanceof OrderFormData) {
        return null;
    }

    if (RentalType::UNLIMITED === $data->rentalType) {
        return 'monthly';
    }

    if (null === $data->startDate || null === $data->endDate) {
        return null;
    }

    $days = (int) $data->startDate->diff($data->endDate)->days;
    if ($days <= 0) {
        return null;
    }

    return $days < PriceCalculator::WEEKLY_THRESHOLD_DAYS ? 'weekly' : 'monthly';
}
```

No new imports — `RentalType`, `PriceCalculator`, and `OrderFormData` are already imported (lines 11, 13, 15 respectively).

### 2. Collapse the Ceník section to the applicable rate

File: `templates/components/OrderForm.html.twig`, replace the block at lines 441-455.

Current markup (for reference — to be replaced):

```twig
<div class="space-y-2">
    <h3 class="font-semibold text-gray-900">Ceník</h3>
    <div class="flex justify-between text-sm">
        <span class="text-gray-600">Týdenní sazba</span>
        <span class="font-semibold text-gray-900">{{ weeklyPrice|number_format(0, ',', ' ') }} Kč <span class="text-xs text-gray-500 font-normal">vč. DPH</span></span>
    </div>
    <div class="flex justify-between text-sm">
        <span class="text-gray-600">Měsíční sazba</span>
        <span class="font-semibold text-accent">{{ monthlyPrice|number_format(0, ',', ' ') }} Kč <span class="text-xs text-gray-500 font-normal">vč. DPH</span></span>
    </div>
    <p class="text-xs text-gray-500 mt-2 bg-gray-50 p-2 rounded">
        Pronájem kratší než 4 týdny se účtuje týdenní sazbou,
        delší pronájem měsíční sazbou (výhodnější).
    </p>
</div>
```

Replace with:

```twig
{% set applicableRate = this.applicableRate %}
<div class="space-y-2">
    <h3 class="font-semibold text-gray-900">Ceník</h3>

    {% if applicableRate is null or applicableRate == 'weekly' %}
        <div class="flex justify-between text-sm">
            <span class="text-gray-600">
                Týdenní sazba
                {% if applicableRate == 'weekly' %}
                    <span class="ml-1 text-xs font-medium text-accent">(platí pro vás)</span>
                {% endif %}
            </span>
            <span class="font-semibold {{ applicableRate == 'weekly' ? 'text-accent' : 'text-gray-900' }}">{{ weeklyPrice|number_format(0, ',', ' ') }} Kč <span class="text-xs text-gray-500 font-normal">vč. DPH</span></span>
        </div>
    {% endif %}

    {% if applicableRate is null or applicableRate == 'monthly' %}
        <div class="flex justify-between text-sm">
            <span class="text-gray-600">
                Měsíční sazba
                {% if applicableRate == 'monthly' %}
                    <span class="ml-1 text-xs font-medium text-accent">(platí pro vás)</span>
                {% endif %}
            </span>
            <span class="font-semibold {{ applicableRate == 'monthly' ? 'text-accent' : 'text-gray-900' }}">{{ monthlyPrice|number_format(0, ',', ' ') }} Kč <span class="text-xs text-gray-500 font-normal">vč. DPH</span></span>
        </div>
    {% endif %}

    {% if applicableRate is null %}
        <p class="text-xs text-gray-500 mt-2 bg-gray-50 p-2 rounded">
            Pronájem kratší než 4 týdny se účtuje týdenní sazbou,
            delší pronájem měsíční sazbou (výhodnější).
        </p>
    {% endif %}
</div>
```

Notes:
- `this.applicableRate` resolves to `getApplicableRate()` via Twig's standard property accessor — no `@ExposeInTemplate` needed.
- The `(platí pro vás)` Czech inline label uses full diacritics ("í").
- Monthly's accent colour is preserved in the undecided state (matches today's emphasis on monthly as the "better" rate). When `weekly` is the applicable rate, weekly takes the accent and monthly is hidden; when `monthly` is applicable, monthly keeps accent and weekly is hidden. So the "highlighted price = the price you'll pay" reading is consistent.
- The explainer paragraph stays in the DOM only while `applicableRate is null` — once the form determines a rate, the explainer is redundant and disappears.

## Acceptance

- Customer lands on `/objednavka/{placeId}/{storageTypeId}` (default LIMITED, no dates): sidebar shows BOTH rates + the "kratší než 4 týdny" explainer (visually unchanged from today).
- Customer toggles rentalType radio to UNLIMITED: weekly row disappears, only `Měsíční sazba (platí pro vás)` remains, explainer gone.
- Customer toggles back to LIMITED without dates: both rates + explainer reappear.
- Customer picks startDate + endDate spanning < 28 days: only `Týdenní sazba (platí pro vás)` shown, with accent colour; monthly row hidden; explainer gone.
- Customer picks startDate + endDate spanning ≥ 28 days: only `Měsíční sazba (platí pro vás)` shown; weekly row hidden; explainer gone.
- Customer uses one of the quick-duration preset buttons (`1 měsíc` / `3 měsíce` / `6 měsíců` from spec 039) — Ceník panel collapses to monthly without an extra click (the existing `blur` dispatch from the preset controller already triggers a Live re-render).
- Updates happen via the existing Live UX blur cycle — no extra Stimulus controller, no `data-live-ignore`, no manual refresh.
- `composer quality` is green.

## Out of scope

- The dynamic payment-schedule block (lines 257-292) — already shows the authoritative charges, untouched here. The Ceník panel above it complements it (per-period rate) rather than duplicates it (full schedule).
- Hiding the entire Ceník section when the user has picked UNLIMITED + an UNLIMITED-billed contract would conflict with showing the customer the actual monthly rate they'll see month after month. Keep monthly visible — that's the whole point of the panel.
- Tooltip / explanation popovers on the `(platí pro vás)` chip — the label itself is self-explanatory.
- Touching the explainer wording, fonts, colours, or layout of the surrounding sidebar.
- Any change to `PriceCalculator`, `OrderFormData`, `OrderFormType`, `OrderCreateController`, or the order_accept / order_payment templates — the Ceník panel is only on the order_create surface.
- Migrations, new entities, new commands/events — none needed.

## Open questions

None — proceed.
