# 052 — Restructure pricing into four tiers (weekly / short-term monthly / long-term monthly / yearly)

**Status:** done
**Type:** feature
**Scope:** large (~35 files)
**Depends on:** none

## Problem

Today every StorageType has three price tiers: weekly (`defaultPricePerWeek`, < 28 days), monthly (`defaultPricePerMonth`, ≥ 28 days + unlimited), and an optional yearly (`defaultPricePerYear`). The single monthly rate conflates short-term (1–6 month) and long-term (6+ month / unlimited) tenants. The operator wants to charge more for short stints and reward long-term commitment with a lower rate. The yearly tier is optional — the operator wants all four tiers always filled and visible.

## Goal

Four required pricing tiers on every StorageType (and optional per-Storage overrides):

| Tier | Czech label | Duration band | Threshold |
|------|------------|---------------|-----------|
| Týdenní | Týdenní sazba | 1–30 days | `< 31 days` |
| Krátkodobá měsíční | Krátkodobá měsíční sazba | 1–6 months | `≥ 31 && < 180 days` |
| Dlouhodobá měsíční | Dlouhodobá měsíční sazba | 6+ months + unlimited | `≥ 180 days` or `UNLIMITED` |
| Roční | Roční sazba | yearly upfront | unchanged (≥ 360 days or UNLIMITED) |

Yearly gets a **"Zvýhodněná cena"** badge wherever displayed. All four are required on StorageType (no nulls). Per-Storage overrides remain optional. Weekly threshold moves from 28 → 31 days (so exactly 30 days is still weekly).

## Context (current state)

**Entities:**
- `src/Entity/StorageType.php:71-73` — `defaultPricePerWeek` (int, required), `defaultPricePerMonth` (int, required)
- `src/Entity/StorageType.php:47-48` — `defaultPricePerYear` (nullable int, optional)
- `src/Entity/Storage.php:28-38` — `pricePerWeek`, `pricePerMonth`, `pricePerYear` (all nullable overrides)
- `src/Entity/Storage.php:172-218` — `getEffectivePricePerWeek/Month/Year()` fallback chain
- `src/Entity/Contract.php:347-349` — `getEffectiveMonthlyAmount()` falls back to `storage->getEffectivePricePerMonth()` — **critical**: after this change `getEffectivePricePerMonth()` becomes the short-term rate; unlimited/long-term contracts must fall back to the new long-term getter instead

**Calculator:**
- `src/Service/PriceCalculator.php:17` — `WEEKLY_THRESHOLD_DAYS = 28` (changes to 31)
- `src/Service/PriceCalculator.php:26` — `YEARLY_THRESHOLD_DAYS = 360` (unchanged)
- `src/Service/PriceCalculator.php:202-216` — `resolveRateType()` returns `'weekly'|'monthly'|'yearly'`
- `src/Service/PriceCalculator.php:62-114` — `calculatePrice/ForStorage()` pick weekly or monthly rate
- `src/Service/PriceCalculator.php:257-330` — `buildPaymentSchedule()` reads `getEffectivePricePerMonth()` for monthly cadence
- `src/Service/PriceCalculator.php:479-531` — `getPriceBreakdown()` reads `storageType->defaultPricePerMonth`

**Forms:**
- `src/Form/StorageTypeFormData.php:47-61` — `defaultPricePerWeek`, `defaultPricePerMonth` (required), `defaultPricePerYear` (optional)
- `src/Form/StorageTypeFormType.php:78-107` — form field config for all three
- `src/Form/StorageFormData.php:39-48` — per-storage overrides (all optional)
- `src/Form/StorageFormType.php:105-136` — per-storage override fields

**Commands:**
- `src/Command/CreateStorageTypeCommand.php:20-22` — `defaultPricePerMonth` (int), `defaultPricePerYear` (?int)
- `src/Command/UpdateStorageTypeCommand.php:20-22` — same
- `src/Command/UpdateStorageCommand.php:19-21` — `pricePerMonth`, `pricePerYear` (both ?int)

**Handlers:**
- `src/Command/CreateStorageTypeHandler.php:38,57` — passes prices to entity constructor
- `src/Command/UpdateStorageTypeHandler.php:33` — passes to `updateDetails()`

**Controllers:**
- `src/Controller/Portal/StorageTypeCreateController.php:44-48` — CZK→haléře conversion
- `src/Controller/Portal/StorageTypeEditController.php:53` — same
- `src/Controller/HomeController.php:57-59` — `lowestPrice` from `type.getDefaultPricePerMonthInCzk()`

**Templates (pricing display):**
- `templates/components/OrderForm.html.twig:508-559` — Ceník sidebar panel (weekly/monthly/yearly rows)
- `templates/components/AdminOnboardingForm.html.twig:62,132,136` — storage type dropdown + selected storage prices
- `templates/partials/place_detail_content.html.twig:116-127` — Týdenní + Měsíční on place detail cards
- `templates/public/place_pricelist.html.twig:98-107,177-193` — pricelist table (desktop) + mobile cards
- `templates/portal/storage_type/create.html.twig:72-84` — price fields (only weekly + monthly rendered)
- `templates/portal/storage_type/edit.html.twig:123-137` — same
- `templates/portal/storage_type/occupancy.html.twig:35` — header monthly price

**Repository:**
- `src/Repository/StorageRepository.php:75-96` — `getEffectiveMonthlyPriceRangeForType()` — SQL `COALESCE(s.pricePerMonth, st.defaultPricePerMonth)`

**Validation:**
- `src/Form/OrderFormData.php:286,309` — uses `WEEKLY_THRESHOLD_DAYS` for billing mode auto-correction
- `src/Form/AdminOnboardingFormData.php:229` — uses `YEARLY_THRESHOLD_DAYS`

**Live Components:**
- `src/Twig/Components/OrderForm.php:122,145,164-189` — `isEligibleForBillingModeChoice()`, `isEligibleForFrequencyChoice()`, `getApplicableRate()`
- `src/Twig/Components/AdminOnboardingForm.php:171-172` — passes `pricePerMonth`/`pricePerWeek` to JS payload

**Exports:**
- `src/Controller/Portal/StorageExportController.php:64,81` — "Cena/měsíc" column

**Fixtures:**
- `fixtures/StorageTypeFixtures.php:70-71,84-85,101-102,108,...` — all StorageType constructors

**Tests:**
- ~15 unit test files create `StorageType` with `defaultPricePerWeek`/`defaultPricePerMonth` constructor args

## Requirements

### 1. New constant + threshold changes (`PriceCalculator`)

```php
public const int WEEKLY_THRESHOLD_DAYS = 31;        // was 28
public const int SHORT_TERM_THRESHOLD_DAYS = 180;   // NEW — < 180 = short-term, ≥ 180 = long-term
public const int YEARLY_THRESHOLD_DAYS = 360;       // unchanged
```

`resolveRateType()` returns `'weekly'|'monthly_short'|'monthly_long'|'yearly'`:
- `YEARLY` → `'yearly'`
- `UNLIMITED` → `'monthly_long'`
- `days < 31` → `'weekly'`
- `days < 180` → `'monthly_short'`
- `days >= 180` → `'monthly_long'`

`calculatePrice()`, `calculatePriceForStorage()`, `buildPaymentSchedule()`, `getPriceBreakdown()`: use the new tier resolution to pick the correct monthly rate (`getEffectivePricePerMonth()` for short-term, `getEffectivePricePerMonthLongTerm()` for long-term).

`needsRecurringBilling()`: update threshold from `WEEKLY_THRESHOLD_DAYS` (was 28, now 31).

### 2. Entity: `StorageType` — add `defaultPricePerMonthLongTerm`, make yearly required

Add constructor parameter + column:
```php
#[ORM\Column]
private(set) int $defaultPricePerMonthLongTerm,
```

Change `defaultPricePerYear`:
```php
#[ORM\Column]                    // remove nullable: true
private(set) int $defaultPricePerYear,  // was ?int
```

Add getter:
```php
public function getDefaultPricePerMonthLongTermInCzk(): float
{
    return $this->defaultPricePerMonthLongTerm / 100;
}
```

Change `getDefaultPricePerYearInCzk()` return type from `?float` to `float` (no more null check).

Update `updateDetails()` signature: `?int $defaultPricePerYear` → `int $defaultPricePerYear`, add `int $defaultPricePerMonthLongTerm` parameter.

### 3. Entity: `Storage` — add `pricePerMonthLongTerm` override

```php
#[ORM\Column(nullable: true)]
public private(set) ?int $pricePerMonthLongTerm = null;
```

Add:
```php
public function getEffectivePricePerMonthLongTerm(): int
{
    return $this->pricePerMonthLongTerm ?? $this->storageType->defaultPricePerMonthLongTerm;
}

public function getEffectivePricePerMonthLongTermInCzk(): float
{
    return $this->getEffectivePricePerMonthLongTerm() / 100;
}
```

Update `updatePrices()` to accept `?int $pricePerMonthLongTerm`.

Update `getEffectivePricePerYear()` fallback: change `$this->storageType->defaultPricePerMonth * 12` to `$this->storageType->defaultPricePerMonthLongTerm * 12` (yearly fallback should base off the long-term monthly, not short-term).

Update `hasCustomPrices()` to include `pricePerMonthLongTerm`.

### 4. Entity: `Contract` — fix `getEffectiveMonthlyAmount()` fallback

```php
public function getEffectiveMonthlyAmount(): int
{
    if (null !== $this->individualMonthlyAmount) {
        return $this->individualMonthlyAmount;
    }

    return $this->isLongTermMonthly()
        ? $this->storage->getEffectivePricePerMonthLongTerm()
        : $this->storage->getEffectivePricePerMonth();
}

private function isLongTermMonthly(): bool
{
    if (RentalType::UNLIMITED === $this->rentalType || null === $this->endDate) {
        return true;
    }

    return (int) $this->startDate->diff($this->endDate)->days >= PriceCalculator::SHORT_TERM_THRESHOLD_DAYS;
}
```

### 5. Commands — add `defaultPricePerMonthLongTerm`, make yearly non-nullable

**`CreateStorageTypeCommand`**: add `public int $defaultPricePerMonthLongTerm`, change `?int $defaultPricePerYear` → `int $defaultPricePerYear`.

**`UpdateStorageTypeCommand`**: same changes.

**`UpdateStorageCommand`**: add `public ?int $pricePerMonthLongTerm = null`.

Update handlers accordingly.

### 6. Forms — add long-term monthly field, make yearly required

**`StorageTypeFormData`:**
- Add property with validation:
  ```php
  #[Assert\NotNull(message: 'Zadejte dlouhodobou měsíční cenu')]
  #[Assert\PositiveOrZero(message: 'Dlouhodobá měsíční cena musí být nula nebo kladná')]
  public ?float $defaultPricePerMonthLongTerm = null;
  ```
- Change `defaultPricePerYear` from optional to required:
  ```php
  #[Assert\NotNull(message: 'Zadejte roční cenu')]
  #[Assert\PositiveOrZero(message: 'Cena za rok musí být nula nebo kladná')]
  public ?float $defaultPricePerYear = null;
  ```
- Update `fromStorageType()` to read the new field.
- Rename validation messages: `defaultPricePerMonth`'s message → `'Zadejte krátkodobou měsíční cenu'`.

**`StorageTypeFormType`:**
- Rename `defaultPricePerMonth` label: `'Krátkodobá měsíční sazba (Kč)'`, help: `'Pro pronájem 1–6 měsíců'`.
- Add `defaultPricePerMonthLongTerm` field: `'Dlouhodobá měsíční sazba (Kč)'`, help: `'Pro pronájem 6+ měsíců a na dobu neurčitou'`.
- Change `defaultPricePerYear` to required, label: `'Roční sazba (Kč)'`, help: `'Platba na celý rok dopředu — zvýhodněná cena'`.

**`StorageFormData`:**
- Add `?float $pricePerMonthLongTerm = null` with `PositiveOrZero` constraint.
- Update `fromStorage()`.

**`StorageFormType`:**
- Add `pricePerMonthLongTerm` field (optional override).

### 7. Controllers — CZK→haléře for new fields

**`StorageTypeCreateController`** (~line 44-48): add `$defaultPricePerMonthLongTerm` conversion, remove null-guard on yearly (always present).

**`StorageTypeEditController`** (~line 53): same.

**`StorageEditController`**: pass `pricePerMonthLongTerm` to `UpdateStorageCommand`.

**`HomeController`** (line 57): change `lowestPrice` source from `type.getDefaultPricePerMonthInCzk()` → `type.getDefaultPricePerMonthLongTermInCzk()` (homepage "Od X Kč" should advertise the cheapest recurring rate).

**`PlaceBrowseListController`** (line 40): same change for `getLowestPrice()` helper.

### 8. Repository — extend priceRange queries

**`StorageRepository::getEffectiveMonthlyPriceRangeForType()`**: this currently returns a single `{min, max}` range for the short-term monthly. Add a parallel `getEffectiveLongTermMonthlyPriceRangeForType()` method (same SQL but `COALESCE(s.pricePerMonthLongTerm, st.defaultPricePerMonthLongTerm)`).

**`PlaceDetailController`** + **`PlacePricelistController`**: compute and pass both `priceRanges` and `priceRangesLongTerm` to templates.

### 9. Templates — storage type create/edit (admin/landlord forms)

**`templates/portal/storage_type/create.html.twig`** (~line 72-84): change from 2-col grid to 4 fields:
```
Cena za týden | Krátkodobá měsíční | Dlouhodobá měsíční | Roční
```
Use a 2×2 grid or 4-col layout. Labels: "Cena za týden", "Krátkodobá (1–6 měs.)", "Dlouhodobá (6+ měs.)", "Roční".

**`templates/portal/storage_type/edit.html.twig`** (~line 123-137): same.

**`templates/portal/storage/edit.html.twig`** (~line 83-101): add `pricePerMonthLongTerm` override field alongside existing three.

### 10. Template — OrderForm Ceník panel

**`templates/components/OrderForm.html.twig`** (lines 508-559):

Extract variables at top of sidebar:
```twig
{% set longTermMonthlyPrice = selectedStorage.effectivePricePerMonthLongTermInCzk %}
```

Replace current 3-row Ceník with 4 rows. Show/hide logic based on `applicableRate`:

| `applicableRate` | Rows shown |
|---|---|
| `null` (LIMITED, dates unknown) | weekly + short-term + long-term + yearly (if eligible) |
| `'weekly'` | weekly only |
| `'monthly_short'` | short-term only |
| `'monthly_long'` | long-term only |
| `'yearly'` | yearly only |

UNLIMITED with no dates: show long-term + yearly (if eligible) — skip weekly and short-term since they can never apply.

Labels:
- "Týdenní sazba" — unchanged
- "Krátkodobá měsíční sazba (1–6 měs.)"
- "Dlouhodobá měsíční sazba (6+ měs.)"
- "Roční sazba" + green badge: `<span class="ml-1 inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">Zvýhodněná cena</span>`

Active row gets "(platí pro vás)" accent as today.

Explainer text when `applicableRate is null`:
```
Pronájem do 30 dnů se účtuje týdenní sazbou. 1–6 měsíců krátkodobou měsíční sazbou, 6 měsíců a déle dlouhodobou měsíční sazbou (výhodnější). Pronájem lze platit i ročně předem za zvýhodněnou cenu.
```

### 11. OrderForm Live Component

**`src/Twig/Components/OrderForm.php`:**

`getApplicableRate()` (line 164-189): return `'weekly'|'monthly_short'|'monthly_long'|'yearly'|null`:
```php
if (PaymentFrequency::YEARLY === ...) return 'yearly';
if (RentalType::UNLIMITED === ...) return 'monthly_long';
if (null === dates) return null;
$days = ...;
if ($days < PriceCalculator::WEEKLY_THRESHOLD_DAYS) return 'weekly';
if ($days < PriceCalculator::SHORT_TERM_THRESHOLD_DAYS) return 'monthly_short';
return 'monthly_long';
```

`isEligibleForBillingModeChoice()` (line 122): update `WEEKLY_THRESHOLD_DAYS` usage (value changed from 28 to 31, constant reference stays).

### 12. AdminOnboardingForm Live Component

**`src/Twig/Components/AdminOnboardingForm.php`** (lines 171-172): add `pricePerMonthLongTerm` to JS payload alongside existing two monthly/weekly entries.

**`templates/components/AdminOnboardingForm.html.twig`**:
- Line 62: storage type dropdown hint — show long-term monthly (the "base" rate): `type.defaultPricePerMonthLongTerm / 100`.
- Lines 132-136: selected storage panel — show both monthly tiers + weekly + yearly with tier labels matching the order form Ceník.

### 13. Place detail + pricelist templates

**`templates/partials/place_detail_content.html.twig`** (lines 114-131): replace single "Měsíční sazba" row with two rows:
- "Krátkodobá (1–6 měs.)" — `defaultPricePerMonthInCzk` / `priceRanges`
- "Dlouhodobá (6+ měs.)" — `defaultPricePerMonthLongTermInCzk` / `priceRangesLongTerm`

Keep "Týdenní sazba" row.

**`templates/public/place_pricelist.html.twig`**: desktop table — add a column for "Dlouhodobá měs." and rename existing "Měs." → "Krátkodobá měs.". Mobile cards — add the second monthly tier.

**`templates/portal/storage_type/occupancy.html.twig`** (line 35): show long-term monthly instead of (or alongside) current monthly.

### 14. StorageExport

**`src/Controller/Portal/StorageExportController.php`** (line 64,81): add a second column "Cena/měsíc dlouhodobá (Kč)" with `storage.getEffectivePricePerMonthLongTerm()`. Rename existing column to "Cena/měsíc krátkodobá (Kč)".

### 15. Validation — threshold update propagation

**`OrderFormData.php`** (lines 286, 309): references `PriceCalculator::WEEKLY_THRESHOLD_DAYS` — no code change needed; the constant value changes from 28 → 31 and all references pick it up.

**`AdminOnboardingFormData.php`** (line 229): same — already references the constant.

### 16. Migration

Auto-generate via `bin/console make:migration`. The migration must:

1. Add `default_price_per_month_long_term INT NOT NULL DEFAULT 0` to `storage_type` (temporary default for the ALTER).
2. Backfill: `UPDATE storage_type SET default_price_per_month_long_term = default_price_per_month` (copy existing monthly as starting point).
3. Drop the DEFAULT 0 (optional — Doctrine manages schema from entity metadata).
4. Change `default_price_per_year` from nullable to NOT NULL:
   - Backfill: `UPDATE storage_type SET default_price_per_year = default_price_per_month * 12 WHERE default_price_per_year IS NULL`
   - Then `ALTER COLUMN default_price_per_year SET NOT NULL`.
5. Add `price_per_month_long_term INT DEFAULT NULL` to `storage` (nullable override, same as other overrides).

The operator will then manually adjust actual prices in both local and production databases.

### 17. Fixtures

**`fixtures/StorageTypeFixtures.php`**: every `new StorageType(...)` call gains `defaultPricePerMonthLongTerm: <value>`. Also fill in `defaultPricePerYear` for types that currently lack it. Use realistic values:
- Long-term ≈ 85-90% of short-term monthly (a visible discount)
- Yearly ≈ long-term × 10 (≈17% off vs 12× long-term)

### 18. Tests

All unit tests that construct `StorageType` (≈15 test files in `tests/Unit/`) must add the `defaultPricePerMonthLongTerm` constructor arg and update `defaultPricePerYear` from `null` to an int.

Add focused tests in `tests/Unit/Service/PriceCalculatorTest.php`:
- 30-day rental → weekly rate
- 31-day rental → short-term monthly rate
- 179-day rental → short-term monthly rate
- 180-day rental → long-term monthly rate
- UNLIMITED → long-term monthly rate
- YEARLY → yearly rate (unchanged)

## Acceptance

- [ ] `docker compose exec web composer quality` green
- [ ] `docker compose exec web composer test` green (all 1100+ tests)
- [ ] StorageType create/edit forms show all 4 price fields; all required; validation fires on blank
- [ ] Order form Ceník panel shows correct tier for: 15-day rental (weekly), 60-day rental (short-term), 200-day rental (long-term), unlimited rental (long-term), yearly frequency (yearly with badge)
- [ ] Ceník shows all applicable tiers when dates are not yet entered
- [ ] Yearly row always has "Zvýhodněná cena" green badge
- [ ] Active tier highlighted with "(platí pro vás)"
- [ ] Homepage "Od X Kč" uses long-term monthly rate
- [ ] Place detail page shows both monthly tiers with tier labels
- [ ] Place pricelist shows both monthly tiers
- [ ] Admin onboarding picks correct tier price based on selected storage + dates
- [ ] Storage edit form has long-term monthly override field
- [ ] Storage export Excel includes both monthly columns
- [ ] Migration runs cleanly: backfills `defaultPricePerMonthLongTerm` = `defaultPricePerMonth`, fills null `defaultPricePerYear` = `defaultPricePerMonth × 12`
- [ ] `Contract::getEffectiveMonthlyAmount()` returns long-term rate for unlimited contracts (when no individual override)

## Out of scope

- **Changing existing order/contract prices** — existing `Order.firstPaymentPrice` and `Contract.individualMonthlyAmount` are locked-in values; this spec doesn't backfill or recalculate them.
- **Per-customer tier override** — `Contract.individualMonthlyAmount` already handles per-customer pricing; no tier-aware equivalent needed.
- **Email templates** — emails show the locked `Order.firstPaymentPrice`, not the current tier rate. No changes needed.
- **Compliance documents** — VOP, Podmínky opakovaných plateb reference "monthly amount" generically. The tier labels don't need legal-document changes.
- **Admin "change individual price" UI** — spec 032 foundation only; no edit UI exists. Not expanding it here.
- **MRR/YRR dashboard recalculation** — MRR uses `Contract.getEffectiveMonthlyAmount()` which is updated by req 4. No formula changes needed.

## Open questions

None — proceed.
