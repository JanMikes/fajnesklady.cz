# 044 — Předpokládaná doba pronájmu: research-only field on UNLIMITED orders

**Status:** done
**Type:** feature (research instrumentation)
**Scope:** small (~14 files: 1 new enum + 1 entity column + 1 migration + 3 FormData + 3 FormType + 3 Command + 1 OrderService signature + 1 CreateOrderHandler + 1 OrderAcceptController + 1 OrderForm Twig component template + 3 detail templates + tests)
**Depends on:** none

## Problem

Today an UNLIMITED order tells us nothing about how long the customer realistically expects to stay. Admins and landlords look at the order list, see "Na dobu neurčitou", and have no signal whether the customer is parking a couch for 4 months or storing archives for 5 years. Useful for capacity planning, churn forecasting, and deciding whether to push LIMITED contracts harder for short stays.

## Goal

When the customer (or admin onboarding for them) picks **Doba neurčitá**, a required follow-up question appears: *Předpokládaná doba pronájmu?* with three buckets. The answer is stored verbatim on the `Order`, surfaced read-only on all three order detail pages (admin / landlord / customer), and used by nothing else — no pricing, no billing, no business rule. Pure research signal.

When the customer picks LIMITED the field never appears and the column stays NULL.

## Context (current state)

- **Form layer (public):** `src/Form/OrderFormData.php` + `src/Form/OrderFormType.php`. `rentalType` is rendered as an expanded `EnumType` radio (`OrderFormType.php:145-153`) inside the "Typ pronájmu" panel in `templates/components/OrderForm.html.twig:175-207`. The UNLIMITED branch already renders an info card at `OrderForm.html.twig:197-206`. Live UX morphs the form on every blur via `data-action="blur->live#action"` (see `endDate` at lines 220-224) — any new field must keep that pattern so morph + validation cycles work.
- **Form layer (admin):** `src/Form/AdminCreateOnboardingFormData.php` + `AdminCreateOnboardingFormType.php` (default `rentalType = UNLIMITED`, line 66 + 102-110). Mirror in `AdminMigrateCustomerFormData.php` + `AdminMigrateCustomerFormType.php`.
- **Entity:** `src/Entity/Order.php`. `rentalType` is a constructor-promoted private(set) on line 158. New optional fields go as **post-constructor** `public private(set)` columns at the top of the class (same pattern as `paidThroughDate` at line 137 and `individualMonthlyAmount` at line 129) and get set via a behavior method, not constructor — same pattern those two override fields use.
- **Order creation chain:** `OrderAcceptController.php:294-303` builds `CreateOrderCommand` → `CreateOrderHandler.php:23-33` → `OrderService::createOrder()` (`OrderService.php:44-117`) → `new Order(...)`. Admin flows skip this: `AdminCreateOnboardingHandler.php:54` and `AdminMigrateCustomerHandler.php:53` call `OrderService::createOrder()` directly with the admin's `rentalType` / dates / monthly override.
- **Detail pages display "Typ pronájmu":**
  - `templates/admin/order/detail.html.twig:135-143`
  - `templates/portal/landlord/order/detail.html.twig:115-122`
  - `templates/portal/user/order/detail.html.twig:119-127`
  All three use the same `<dl>` 2-column grid; the new field goes inline next to "Typ pronájmu" as a sibling `<div>` rendered only when UNLIMITED.
- **Session round-trip:** `OrderFormData::toSessionArray()` / `fromSessionArray()` (lines 274-329) shuttle form state between the Live component and `OrderAcceptController`. Any new field MUST round-trip through both, with `value` for serialise and `tryFrom` for deserialise (mirror the `billingMode` pair at lines 295 + 324-326).
- **Renew flow:** `Public\OrderRenewController.php:66-88` forces the renewal back to LIMITED. Not affected.
- **PROJECT_MAP.md** currently lists `BillingMode`, `RentalType` etc. — add the new enum after this lands.

## Architecture

New constructor-promoted-style enum + non-constructor entity column. Form layer is independent in three places; storage layer is a single column threaded through `OrderService::createOrder()`.

```
OrderForm (Live)            ┐
AdminCreateOnboardingFormType ┼─► FormData.expectedDuration  ──► OrderService::createOrder($expectedDuration)
AdminMigrateCustomerFormType  ┘                                   └► Order::setExpectedDuration() (only if UNLIMITED)
                                                                       │
                                                       ┌───────────────┼───────────────┐
                                       admin/detail.html.twig  landlord/detail  user/detail
                                              (display only — no behavior)
```

## Requirements

### 1. New enum `App\Enum\ExpectedDuration`

`src/Enum/ExpectedDuration.php` — backed string enum, lowercase snake-case `value` (matches the project's convention: `BillingMode`, `RentalType`, etc.).

```php
<?php

declare(strict_types=1);

namespace App\Enum;

enum ExpectedDuration: string
{
    case SHORT = 'short';   // 3–6 měsíců
    case MEDIUM = 'medium'; // 6–12 měsíců
    case LONG = 'long';     // Více než 1 rok

    public function label(): string
    {
        return match ($this) {
            self::SHORT => '3–6 měsíců',
            self::MEDIUM => '6–12 měsíců',
            self::LONG => 'Více než 1 rok',
        };
    }
}
```

Czech labels MUST use proper diacritics (`měsíců`, `Více`) per the project rule in `MEMORY.md`.

### 2. `Order` entity — new nullable column

`src/Entity/Order.php`. Add immediately under `paidThroughDate` (line 137 area) as a **post-constructor** column, since it doesn't apply to every order and pre-dates customers' answers would force a backfill:

```php
/**
 * Customer's self-reported expected stay length, asked only when they pick
 * UNLIMITED. Research signal for admins/landlords — never read by billing,
 * pricing, or any business rule. NULL for LIMITED orders and for legacy
 * UNLIMITED orders placed before this column existed.
 */
#[ORM\Column(length: 10, nullable: true, enumType: ExpectedDuration::class)]
public private(set) ?ExpectedDuration $expectedDuration = null;
```

Behavior setter (used by `OrderService::createOrder` after the `new Order(...)` call so the column stays out of the already-long constructor signature):

```php
public function setExpectedDuration(?ExpectedDuration $duration): void
{
    $this->expectedDuration = $duration;
}
```

No domain event needed (research-only). No validation in the entity — the form layer is responsible for "required when UNLIMITED" since UNLIMITED is itself only knowable at the form layer.

### 3. Migration

Generated via `docker compose exec web bin/console make:migration` (NEVER handwrite per CLAUDE.md). The diff should be:

```sql
ALTER TABLE orders ADD expected_duration VARCHAR(10) DEFAULT NULL;
COMMENT ON COLUMN orders.expected_duration IS '(DC2Type:string)';
```

Nullable, no default, no backfill — legacy UNLIMITED orders stay NULL and display "Neuvedeno" (req. 7).

### 4. `OrderService::createOrder()` — accept the new field

`src/Service/OrderService.php:44-117`. Add a nullable parameter at the end of the signature (mirrors the existing `?int $monthlyPriceOverride = null` tail):

```php
public function createOrder(
    User $user,
    StorageType $storageType,
    Place $place,
    RentalType $rentalType,
    \DateTimeImmutable $startDate,
    ?\DateTimeImmutable $endDate,
    \DateTimeImmutable $now,
    ?PaymentFrequency $paymentFrequency = null,
    ?Storage $preSelectedStorage = null,
    ?int $monthlyPriceOverride = null,
    ?ExpectedDuration $expectedDuration = null,
): Order {
```

After `$this->orderRepository->save($order);` (line 113) but BEFORE the audit log call:

```php
if (RentalType::UNLIMITED === $rentalType) {
    $order->setExpectedDuration($expectedDuration);
}
```

The `UNLIMITED ===` guard is defensive: if a caller accidentally passes a non-null `expectedDuration` for a LIMITED order, we drop it on the floor rather than poison the column. (LIMITED orders MUST be NULL here — req. 7 displays render guards on the same invariant.)

### 5. `CreateOrderCommand` + `CreateOrderHandler` — pass it through

`src/Command/CreateOrderCommand.php`: add `public ?ExpectedDuration $expectedDuration = null` at the tail of the constructor.

`src/Command/CreateOrderHandler.php:23-33`: forward to `$this->orderService->createOrder(...)` via `expectedDuration: $command->expectedDuration`.

### 6. Public order form

#### 6a. `OrderFormData.php`

Add the property next to `rentalType` (around line 71):

```php
public ?ExpectedDuration $expectedDuration = null;
```

Validation: required ONLY when `rentalType === UNLIMITED`. Add a new `#[Assert\Callback]` (separate from the existing `validateBillingMode` so the violation is per-field, not bundled):

```php
#[Assert\Callback]
public function validateExpectedDuration(ExecutionContextInterface $context): void
{
    if (RentalType::UNLIMITED !== $this->rentalType) {
        return;
    }

    if (null === $this->expectedDuration) {
        $context->buildViolation('Vyberte předpokládanou dobu pronájmu.')
            ->atPath('expectedDuration')
            ->addViolation();
    }
}
```

Session round-trip — add to `toSessionArray()`:
```php
'expectedDuration' => $this->expectedDuration?->value,
```

And `fromSessionArray()` (mirror the `billingMode` tryFrom at line 324-326):
```php
if (isset($data['expectedDuration'])) {
    $formData->expectedDuration = ExpectedDuration::tryFrom($data['expectedDuration']);
}
```

#### 6b. `OrderFormType.php`

Add after the `rentalType` definition (around line 153):

```php
->add('expectedDuration', EnumType::class, [
    'class' => ExpectedDuration::class,
    'label' => 'Předpokládaná doba pronájmu',
    'label_attr' => ['class' => 'required'],
    'expanded' => true,
    'required' => false, // server-side enforced via validateExpectedDuration when UNLIMITED
    'placeholder' => false,
    'choice_label' => fn (ExpectedDuration $d) => $d->label(),
    'help' => 'Informativní údaj pro provozovatele, nemá vliv na cenu ani podmínky pronájmu.',
])
```

- `required: false` is essential — Live UX re-submits the form on every per-field blur with the field potentially empty, same trick used for `billingMode` at line 175-179. The server-side callback is the source of truth.
- `label_attr.class = required` paints the red asterisk via the existing form theme (`form_theme.html.twig`'s `.required` rule already covers this — no theme changes needed).
- `placeholder: false` suppresses Symfony's stray "None" radio when `required: false` is set, exact same trick `billingMode` uses.

#### 6c. `templates/components/OrderForm.html.twig`

Inside the existing UNLIMITED branch (the `{% else %}` at line 197-206) — render the field as the LAST element of that info-card panel so the question reads naturally right under the explainer sentence:

```twig
{% else %}
    <div class="mt-4 p-4 bg-accent/10 border border-accent/20 rounded-lg">
        <p class="text-sm text-gray-700">
            <svg ...> ... </svg>
            Pronájem na dobu neurčitou s možností ukončení kdykoliv. Skladovací jednotka zůstává vaše, dokud smlouvu sami neukončíte.
        </p>
    </div>

    <div class="mt-4">
        {{ form_row(form.expectedDuration, {attr: {
            'data-action': 'change->live#action',
            'data-live-action-param': 'validateField',
            'data-live-field-param': 'expectedDuration',
        }}) }}
    </div>
{% endif %}
```

When the customer flips back to LIMITED the field is no longer rendered, so Symfony's `setRendered` cleanup isn't needed (the `{% else %}` branch simply doesn't fire). But ensure the LIMITED branch (line 188-196) does NOT render `form.expectedDuration` — if Symfony's `form_end()` later complains about unrendered fields, add `{% do form.expectedDuration.setRendered %}` inside the LIMITED branch.

Visual treatment: expanded radio renders one option per line by default; with `expanded: true` and three short labels this is fine. Match the existing `billingMode` radio styling (no extra CSS needed — `form_theme.html.twig` already styles radio groups consistently).

### 7. Admin onboarding form

#### 7a. `AdminCreateOnboardingFormData.php`

Add property next to `rentalType` (around line 66):
```php
public ?ExpectedDuration $expectedDuration = null;
```

Mirror the same `validateExpectedDuration` callback as req. 6a (identical body, same Czech message).

#### 7b. `AdminCreateOnboardingFormType.php`

Add right after `rentalType` (around line 110), same definition as req. 6b. Admin form is not Live-component-driven so omit the `data-action` blur listener — vanilla Symfony radio works.

#### 7c. `AdminCreateOnboardingCommand.php`

Add tail param: `public ?ExpectedDuration $expectedDuration = null`.

#### 7d. `AdminCreateOnboardingController.php:61-84`

Pass `expectedDuration: $formData->expectedDuration` in the `new AdminCreateOnboardingCommand(...)` constructor.

#### 7e. `AdminCreateOnboardingHandler.php:54`

Forward into `OrderService::createOrder(... expectedDuration: $command->expectedDuration)`.

#### 7f. Twig form template

Whichever `.html.twig` renders `AdminCreateOnboardingFormType` (locate via `grep -r 'admin_create_onboarding' templates/`) gets one extra `{{ form_row(form.expectedDuration) }}` rendered conditionally on `form.vars.data.rentalType.value == 'unlimited'`. The admin template is not Live-component-reactive — the field renders on full page submit. **Defensive: render it unconditionally;** if the admin picks LIMITED and the field is empty, the callback skips it (returns at line "if (UNLIMITED !== this->rentalType) return"). Wrap in a short hint: "(Jen pro pronájem na dobu neurčitou.)"

### 8. Admin migrate form

Mirror req. 7 verbatim for:
- `AdminMigrateCustomerFormData.php` — property + callback
- `AdminMigrateCustomerFormType.php` — `->add('expectedDuration', ...)`
- `AdminMigrateCustomerCommand.php` — tail param
- Caller controller dispatching `new AdminMigrateCustomerCommand(...)` — pass through
- `AdminMigrateCustomerHandler.php:53` — forward into `OrderService::createOrder`
- Twig form template — same defensive unconditional render

### 9. Display on detail pages (3 templates, same pattern)

Each detail template renders "Typ pronájmu" as one `<div>` cell inside a `<dl>` grid. Add a **new sibling `<div>` immediately after Typ pronájmu**, rendered only when the value is non-null:

```twig
{% if order.expectedDuration %}
    <div>
        <dt class="text-sm font-medium text-gray-500">Předpokládaná doba pronájmu</dt>
        <dd class="mt-1 text-sm text-gray-900">{{ order.expectedDuration.label }}</dd>
    </div>
{% endif %}
```

Three files (insert after the existing "Typ pronájmu" block):
- `templates/admin/order/detail.html.twig` (after line 143)
- `templates/portal/landlord/order/detail.html.twig` (after line 122)
- `templates/portal/user/order/detail.html.twig` (after line 127)

The `{% if %}` guard handles both cases cleanly: legacy UNLIMITED orders (NULL) hide the row; LIMITED orders never reach this code path with a value (invariant maintained by req. 4's guard). No "Neuvedeno" fallback shown — fewer columns of noise on the dense detail grid.

### 10. PROJECT_MAP.md

Add `ExpectedDuration` to the enums section. One line under `BillingMode` / `RentalType`.

### 11. Tests

- **Unit:** new test class `tests/Unit/Form/OrderFormDataTest.php` (or extend if it exists) — three cases:
  1. `rentalType = UNLIMITED`, `expectedDuration = null` → violation at path `expectedDuration` with the Czech message.
  2. `rentalType = UNLIMITED`, `expectedDuration = SHORT` → no violation.
  3. `rentalType = LIMITED`, `expectedDuration = null` → no violation (field doesn't apply).
- **Unit:** assert `OrderService::createOrder()` drops a non-null `expectedDuration` when `rentalType === LIMITED` (req. 4's defensive guard). Use `PredictableIdentityProvider`, MockClock fixed at `2025-06-15 12:00:00 UTC` per CLAUDE.md.
- Run `docker compose exec web composer test` (1104 tests) — not just `composer quality` — because controller/template/form changes touch integration paths per the `feedback_quality_runs_full_test` memory.

## Acceptance

- [ ] Customer picks UNLIMITED on `/objednavka/...` → "Předpokládaná doba pronájmu" radio appears with red-asterisk label and three options.
- [ ] Customer picks LIMITED → field is not rendered.
- [ ] Submitting UNLIMITED with no duration selected blocks the form with "Vyberte předpokládanou dobu pronájmu." displayed under the field.
- [ ] Order placed end-to-end stores the chosen `ExpectedDuration` on `orders.expected_duration`.
- [ ] Admin order detail (`/portal/admin/objednavky/{id}`), landlord order detail (`/portal/landlord/orders/{id}`), and customer order detail (`/portal/objednavky/{id}`) all show the Czech label inline next to "Typ pronájmu" for UNLIMITED orders.
- [ ] LIMITED orders and pre-migration UNLIMITED orders show no "Předpokládaná doba pronájmu" row on any detail page.
- [ ] Admin onboarding form (`AdminCreateOnboardingFormType`) and migrate form (`AdminMigrateCustomerFormType`) both gain the field; the column is populated when admin picks UNLIMITED and stays NULL when admin picks LIMITED.
- [ ] Migration generated via `make:migration` is reversible (`down()` drops the column).
- [ ] `docker compose exec web composer quality` is green.
- [ ] `docker compose exec web composer test` (full 1104-test suite) is green.

## Out of scope

- **Reports / analytics dashboards.** No new admin chart, no MRR-vs-duration cross-tab, no export column. Pure raw signal collection — operators eyeball it per order. (Build it later once the data has accumulated.)
- **Excel export columns** (spec 028). Could be added trivially but not part of this spec — keeps the diff small. If wanted, file a follow-up.
- **Editing the value after creation.** No admin-side "fix the customer's answer" flow. Locked at creation. (If it ever matters, build a dedicated edit page — don't shoehorn into the existing order edit surface, which doesn't exist.)
- **Migrating legacy UNLIMITED orders.** Old rows stay NULL forever; the detail-page `{% if %}` guard hides the row cleanly. Backfilling would require asking customers, which contradicts the "no behavior, pure self-report" framing.
- **LIMITED-rental quick-duration breakdowns.** Spec 039 already gives customers `1/3/6 měsíců` buttons on LIMITED end-date — that's a different question (actual duration) from this one (expected duration on UNLIMITED).
- **Renew flow** (`OrderRenewController`). Renewals are forced to LIMITED (`OrderRenewController.php:88`), so the field never applies there.

## Open questions

None — proceed.
