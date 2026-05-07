# 025 — Onboarding billing controls: individual price, external prepayment, free contracts (with admin visibility)

**Status:** done
**Type:** feature (admin onboarding UX + billing model + admin visibility)
**Scope:** large (~26 files: 1 entity + 2 handler bug-fixes + 2 onboarding handlers + 4 form classes + 2 templates + 1 new console command + 1 new event + 1 new email handler + 2 templates + repo additions + 3 admin templates + 2 controllers + nav edit + tests + PROJECT_MAP update)
**Depends on:** none (touches the same surfaces as 023/024 but does not block on them)

## Problem

Admin onboarding today (`/portal/admin/onboarding/digital`, `/portal/admin/onboarding/migrate`) creates orders priced strictly from the storage's effective monthly rate. Real customer deals routinely diverge:

- A landlord negotiates **a different monthly rate** for one customer (legacy migration, friend-of-the-house, marketing trial). Today the only escape hatch is `AdminMigrateCustomerCommand::totalPrice` which is a one-shot lump sum — it does not propagate into the recurring schedule, and `ChargeRecurringPaymentHandler:134` silently re-reads `storage.effectivePricePerMonth` next month, voiding the negotiated price.
- A customer **prepays externally** (cash / bank transfer) through a date and is expected to switch to the standard recurring billing process **after** that date. There is no way to record "paid through 2026-12-31, then continue monthly". Migrate forces a single one-shot prepayment with no recurring tail; digital onboarding has no prepayment field at all.
- Some customers are **free** (internal, charity, owner test). There is no way to mark a contract as zero-priced; today admins would have to set storage price to zero, which leaks to every other customer of that storage.
- Even when admins know about these arrangements, **none of them are visible at a glance**. The admin order list shows the same single status badge for an onboarded-with-individual-price-and-prepayment contract as for a vanilla GoPay order. There is no way to filter "show me everyone on external prepayment that's about to end" or "show me everyone on an individual price".

The recurring-charge bug is the load-bearing one — without fixing it, every other change in this spec is a lie the second month rolls around.

## Goal

1. **Onboarding admin can set the billing model on every contract** — both digital and migrate flows expose the same three knobs:
   - **Měsíční cena** — Standardní (storage default) / Individuální (custom Kč) / Zdarma (0).
   - **Externí předplatné** — optional checkbox + "Předplaceno do" date. When set, the contract is paid through that date and the cron does not bill until then.
   - The form auto-warns when the chosen combination is non-trivial, and the resulting Order/Contract carries the locked-in monthly through every billing cycle.
2. **The recurring cron honours the locked-in monthly.** `ChargeRecurringPaymentHandler` reads from `Contract::getEffectiveMonthlyAmount()` (individual override → fallback to storage default) instead of `storage.effectivePricePerMonth` directly. Existing contracts (override null) charge the same as before.
3. **Free contracts cost zero, every cycle.** No GoPay charge attempt, no invoice, no Fakturoid call, no self-billing entry. The contract still appears in lists with a clear "Zdarma" badge.
4. **External prepayment expires gracefully.** A daily cron sends a customer reminder 7 days before `paidThroughDate` and a courtesy admin alert; once the date passes without a GoPay setup, the contract appears in the existing Po splatnosti list (spec 023 — `OverdueChecker` already detects `nextBillingDate < now-1day` without `goPayParentPaymentId`). The customer-side conversion flow is **out of scope here** (deferred to a follow-up spec).
5. **Admin sees all of this on first sight:**
   - **Order list** (`/portal/admin/orders`): row badges `Indiv. cena` (orange) / `Předplaceno do {date}` (blue) / `Zdarma` (green) / `Externí` (gray), and a filter strip `Vše · Indiv. cena · Externí předplatné · Předplatné brzy končí · Zdarma`.
   - **Order detail** (`/portal/admin/orders/{id}`): a prominent info banner above the content showing all three dimensions when any apply, beneath the existing "Dlužník" banner.
   - **User list** (`/portal/users`): an "Onboardovaný" badge on customers whose latest contract was admin-onboarded, with a filter chip alongside the existing "Dlužník".

## Context (current state)

### Onboarding flow surfaces

- **Hub**: `/portal/admin/onboarding` → `Admin\AdminOnboardingController` → `templates/admin/onboarding/index.html.twig`. Two cards: Migrace papírové smlouvy / Digitální onboarding.
- **Digital**: `/portal/admin/onboarding/digital` → `Admin\AdminCreateOnboardingController` → form `AdminCreateOnboardingFormType`(`Data`) → `AdminCreateOnboardingCommand`/`Handler` → emits `AdminOnboardingInitiated`. Customer signs at `/podpis/{token}` (`Public\CustomerSigningController` → `CustomerSignOnboardingCommand`/`Handler`). For `PaymentMethod::EXTERNAL` the handler auto-confirms payment + completes the order.
- **Migrate**: `/portal/admin/onboarding/migrate` → `Admin\AdminMigrateCustomerController` → form `AdminMigrateCustomerFormType`(`Data`) → `AdminMigrateCustomerCommand`/`Handler`. Uploads PDF, takes `totalPrice` (lump sum) + `paidAt`, marks `EXTERNAL`, accepts terms, reserves storage, confirms payment, completes order, attaches PDF, signs contract.

### Pricing primitives

- `App\Service\PriceCalculator::calculateFirstPaymentPrice(Storage, start, ?end): int` (halere). Returns the monthly rate for recurring (≥28 days or unlimited) or the full one-shot price for short rentals.
- `Storage::getEffectivePricePerMonth(): int` — overrides storage type default if `Storage.pricePerMonth` set.
- `OrderService::createOrder(...)` populates `Order.firstPaymentPrice = $priceCalculator->calculateFirstPaymentPrice(...)` (`src/Service/OrderService.php:78`).
- `AdminMigrateCustomerHandler` overrides this via `Order::overrideFirstPaymentPrice($command->totalPrice)` (`src/Command/AdminMigrateCustomerHandler.php:67`) — but this is the lump sum, not a monthly. Once the contract enters recurring mode, the cron ignores it.

### The recurring-charge bug

`ChargeRecurringPaymentHandler::calculateBillingAmount()` (`src/Command/ChargeRecurringPaymentHandler.php:134`):

```php
$monthlyRate = $contract->storage->getEffectivePricePerMonth();
```

Every recurring charge re-reads the **current** storage price. Side effects:

- Onboarding's lump-sum override (`Order::overrideFirstPaymentPrice`) only applies to the first invoice; from month two onward the customer is billed at the storage default.
- `ProcessPaymentNotificationHandler:126` — `$amount = $status->amount ?? $contract->storage->getEffectivePricePerMonth()` — same pattern, same fallback bug.
- Already inconsistent with the rest of the codebase, which trusts `Order.firstPaymentPrice` as the locked-in monthly: `OverdueChecker:100`, `ContractRepository:418/530/550/613`, `OrderRepository:217`.

This spec fixes both call sites by routing through a new `Contract::getEffectiveMonthlyAmount()` accessor — keeps the change surgical, makes intent explicit, and gives tests a single seam.

### External-payment plumbing already in place

- `PaymentMethod::EXTERNAL` enum case (`src/Enum/PaymentMethod.php`).
- `Order::paymentMethod` (`src/Entity/Order.php:75`) — already persisted.
- `Order::isAdminCreated` (`Order.php:69`) + `Order::signingToken` (`Order.php:72`) — already mark onboarded orders.
- `Contract::paidThroughDate` (`src/Entity/Contract.php:54`) — already exists, maintained by `Contract::setRecurringPayment(...)` and `recordBillingCharge(...)`. Currently never set without a `goPayParentPaymentId`. This spec changes that for external-prepaid contracts: `paidThroughDate` is set, `goPayParentPaymentId` stays null, `nextBillingDate` is set to the day after `paidThroughDate`.

### Admin visibility surfaces (mirror spec 023's pattern)

- **Order list**: `templates/admin/order/list.html.twig` — already shows the "Dlužník" badge in the customer column via `debtorIdSet[user.id.toRfc4122()]`. Status column already prints a coloured badge per `OrderStatus`.
- **User list**: `templates/portal/user/list.html.twig` — already has filter chips (`Vše` / `Pouze dlužníci ({count})`) and the "Dlužník" badge in the Stav column.
- **Order detail**: `templates/admin/order/detail.html.twig` — has room above the content for a banner.
- **Filter pattern**: simple anchor buttons toggling query params, matching spec 023's pattern.

### Overdue-detection interaction

`ContractRepository::findWithPaymentIssues()` (`src/Repository/ContractRepository.php:331`) flags contracts where `nextBillingDate < now-1day` AND `terminatedAt IS NULL` AND `failedBillingAttempts > 0` OR (`nextBillingDate < now-1day`). External-prepaid contracts get `nextBillingDate = paidThroughDate + 1 day`; while still prepaid (`nextBillingDate > now`) they are correctly excluded. **After** the prepayment expires without a GoPay setup, they correctly appear as overdue — **this is the desired behaviour** (Goal 4). Free contracts have `individualMonthlyAmount = 0` and `nextBillingDate IS NULL` and `failedBillingAttempts = 0`, so they are correctly excluded.

### Cron pattern to mirror

`SendRecurringPaymentAdvanceNoticeCommand` (`src/Console/SendRecurringPaymentAdvanceNoticeCommand.php`) — daily cron: query repository → dispatch one event per match → handler renders + sends e-mail. The new "external prepayment ending in 7 days" cron mirrors this exactly.

## Architecture

```
                ┌──────────────────────────────────────────────────┐
                │  Form (Digital + Migrate)                        │
                │  + monthlyPriceMode (standard | custom | free)   │
                │  + customMonthlyPriceInCzk                       │
                │  + isExternallyPrepaid                           │
                │  + paidThroughDate                               │
                └───────────────────┬──────────────────────────────┘
                                    │
                ┌───────────────────▼──────────────────────────────┐
                │  AdminCreateOnboardingCommand (extended)         │
                │  AdminMigrateCustomerCommand (extended)          │
                │  + individualMonthlyAmount: ?int                 │
                │  + paidThroughDate: ?DateTimeImmutable           │
                └───────────────────┬──────────────────────────────┘
                                    │
                ┌───────────────────▼──────────────────────────────┐
                │  Handlers                                        │
                │  - Set Order.firstPaymentPrice =                 │
                │       individualMonthlyAmount ?? storageMonthly  │
                │  - On contract creation:                         │
                │       Contract.individualMonthlyAmount = ...     │
                │       Contract.paidThroughDate = ...             │
                │       Contract.nextBillingDate = paidThrough+1d  │
                └───────────────────┬──────────────────────────────┘
                                    │
                ┌───────────────────▼──────────────────────────────┐
                │  Contract::getEffectiveMonthlyAmount(): int      │
                │     individualMonthlyAmount ?? storageMonthly    │
                │                                                  │
                │  Used by:                                        │
                │  - ChargeRecurringPaymentHandler                 │
                │  - ProcessPaymentNotificationHandler             │
                │  - OverdueChecker (replace order.firstPayment)   │
                └──────────────────────────────────────────────────┘

                Daily cron (mirrors SendRecurringPaymentAdvanceNoticeCommand):
                ┌──────────────────────────────────────────────────┐
                │  SendExternalPrepaymentEndingSoonCommand         │
                │  → ContractRepo::findExternalPrepaymentEnding(N) │
                │  → dispatches ExternalPrepaymentEndingSoon       │
                │  → SendExternalPrepaymentEndingSoonEmailHandler  │
                │     · customer template (with "kontaktujte nás") │
                │     · admin courtesy CC                          │
                └──────────────────────────────────────────────────┘

                Admin visibility:
                ┌──────────────────────────────────────────────────┐
                │  AdminOrderListController                        │
                │  - filter param: indiv | external | ending | free│
                │  - badges from Contract via order.id 1:1 lookup  │
                │  AdminUserListController                         │
                │  - filter param: onboarded                       │
                │  - onboarded set via UserRepo::findOnboardedIds  │
                │  AdminOrderDetailController                      │
                │  - banner partial templates/admin/order/         │
                │      _onboarding_banner.html.twig                │
                └──────────────────────────────────────────────────┘
```

## Requirements

### 1. Entity: `Contract.individualMonthlyAmount` + `getEffectiveMonthlyAmount()`

`src/Entity/Contract.php` — add:

```php
/**
 * Per-contract monthly recurring price in halere (CZK × 100). When set, this
 * overrides the current storage rate for ALL future recurring charges and
 * for any code projecting the "locked-in monthly". Set during admin
 * onboarding (specs 025) for individual-price or free contracts.
 *
 *  null → use storage.effectivePricePerMonth (default behaviour)
 *  0    → free contract: skip charging, skip invoicing
 *  > 0  → custom monthly that survives storage-price changes
 */
#[ORM\Column(nullable: true)]
public private(set) ?int $individualMonthlyAmount = null;
```

Add behaviour methods (no setters — use behaviour methods per CLAUDE.md):

```php
public function applyIndividualMonthlyAmount(?int $amount): void
{
    if (null !== $amount && $amount < 0) {
        throw new \InvalidArgumentException('Individual monthly amount cannot be negative.');
    }
    $this->individualMonthlyAmount = $amount;
}

public function getEffectiveMonthlyAmount(): int
{
    return $this->individualMonthlyAmount ?? $this->storage->getEffectivePricePerMonth();
}

public function hasIndividualPrice(): bool
{
    return null !== $this->individualMonthlyAmount;
}

public function isFree(): bool
{
    return 0 === $this->individualMonthlyAmount;
}
```

Migration: generate via `docker compose exec web bin/console make:migration` after adding the column. **Do not handwrite SQL** (per CLAUDE.md).

### 2. Bug-fix: `ChargeRecurringPaymentHandler` reads from contract, not storage

`src/Command/ChargeRecurringPaymentHandler.php:134` — replace:

```php
$monthlyRate = $contract->storage->getEffectivePricePerMonth();
```

with:

```php
$monthlyRate = $contract->getEffectiveMonthlyAmount();
```

The early-return at line 50 (`if ($amount <= 0) ... return;`) already covers free contracts — no change.

**Compliance note** (`.claude/COMPLIANCE.md`, recurring-payment cap): the new accessor must still respect `PriceCalculator::MAX_RECURRING_PAYMENT_AMOUNT_IN_HALER` (15 000 Kč). Add a guard in `Contract::applyIndividualMonthlyAmount()`:

```php
if (null !== $amount && $amount > \App\Service\PriceCalculator::MAX_RECURRING_PAYMENT_AMOUNT_IN_HALER) {
    throw new \DomainException(sprintf(
        'Individual monthly amount %d Kč exceeds the legal recurring-payment maximum of %d Kč.',
        $amount / 100,
        \App\Service\PriceCalculator::MAX_RECURRING_PAYMENT_AMOUNT_IN_HALER / 100,
    ));
}
```

### 3. Bug-fix: `ProcessPaymentNotificationHandler` mirror

`src/Command/ProcessPaymentNotificationHandler.php:126` — same swap:

```php
$amount = $status->amount ?? $contract->getEffectiveMonthlyAmount();
```

### 4. `Order.firstPaymentPrice` consistency at onboarding creation

`Order.firstPaymentPrice` must equal the **locked-in monthly** for recurring orders. Today, both `AdminCreateOnboardingHandler` and `OrderService::createOrder` use the storage rate. When admin sets an individual monthly, both must use it.

**Strategy**: pass the override down from the command into `OrderService::createOrder` via an additional optional parameter, then override after creation if needed. Concretely, add to `OrderService`:

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
    ?int $monthlyPriceOverride = null,   // NEW — null = use PriceCalculator default
): Order {
    ...
    $firstPaymentPrice = $monthlyPriceOverride
        ?? $this->priceCalculator->calculateFirstPaymentPrice($storage, $startDate, $endDate);
    ...
}
```

Both onboarding handlers pass through. The migrate handler stops calling `Order::overrideFirstPaymentPrice($command->totalPrice)` (the lump sum) — **the lump sum is no longer the locked-in monthly**; it's a one-shot prepayment whose role is documented in req. 6.

### 5. AdminCreateOnboarding — command + handler + form

#### `AdminCreateOnboardingCommand`

Add fields:

```php
public ?int $individualMonthlyAmount,   // halere; null = standard storage rate; 0 = free
public ?\DateTimeImmutable $paidThroughDate,  // null = no external prepayment
```

#### `AdminCreateOnboardingHandler`

After creating the order:

1. Pass `monthlyPriceOverride: $command->individualMonthlyAmount` to `OrderService::createOrder`.
2. If `$command->paidThroughDate` is set, set `$order->setPaymentMethod(PaymentMethod::EXTERNAL)` regardless of the form's `paymentMethod` choice (external prepaid is by definition external).
3. **Free contracts skip GoPay**: if `$command->individualMonthlyAmount === 0`, force `PaymentMethod::EXTERNAL` (free contracts cannot/should not be billed via GoPay).

The contract is created downstream by `OrderService::completeOrder()` — but only the migrate flow completes synchronously. For digital onboarding the contract is created later, via `CustomerSignOnboardingHandler`. So:

- The **command/handler must defer** writing `individualMonthlyAmount` and `paidThroughDate` to contract creation. Carry them on the order (using two new transient fields would muddy `Order`; instead, **persist them on the order itself** so the signing-handler / migrate-handler can read them when constructing the Contract).

To avoid a wide blast radius, add to `Order`:

```php
#[ORM\Column(nullable: true)]
public private(set) ?int $individualMonthlyAmount = null;

#[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
public private(set) ?\DateTimeImmutable $paidThroughDate = null;

public function setOnboardingBillingTerms(?int $individualMonthlyAmount, ?\DateTimeImmutable $paidThroughDate): void
{
    $this->individualMonthlyAmount = $individualMonthlyAmount;
    $this->paidThroughDate = $paidThroughDate;
}
```

These are write-once at onboarding creation; nothing else should mutate them. Free / individual-price flag the contract once it exists; before then they live on the order.

`AdminCreateOnboardingHandler` calls `$order->setOnboardingBillingTerms($command->individualMonthlyAmount, $command->paidThroughDate);` after `markAsAdminCreated()`.

#### `CustomerSignOnboardingHandler` (downstream)

When constructing the Contract via `OrderService::completeOrder()`, the contract must inherit:

```php
$contract->applyIndividualMonthlyAmount($order->individualMonthlyAmount);
if (null !== $order->paidThroughDate) {
    // Day after prepayment is when the cron should next look at this contract.
    $contract->markExternallyPrepaid($order->paidThroughDate);
}
```

Add `Contract::markExternallyPrepaid(\DateTimeImmutable $paidThroughDate): void` — sets `$this->paidThroughDate = $paidThroughDate; $this->nextBillingDate = $paidThroughDate->modify('+1 day');`. **No** `goPayParentPaymentId` is set — the contract has no recurring token until the customer-side conversion flow runs (out of scope, deferred).

`OrderService::completeOrder()` is called from `CustomerSignOnboardingHandler`'s `CompleteOrderCommand` for `EXTERNAL` orders. **Tweak `OrderService::completeOrder()` to invoke the new contract methods** based on `$order->individualMonthlyAmount` / `$order->paidThroughDate`.

For **free** contracts (`individualMonthlyAmount === 0`), `confirmPayment()` is still called from `CustomerSignOnboardingHandler` so the order transitions to PAID and triggers `OrderPaid`. The downstream invoice-issuer needs a free-contract guard — see req. 8.

#### `AdminCreateOnboardingFormData` / `FormType`

Add a "Cenový model" radio group + conditional fields:

```php
public string $monthlyPriceMode = 'standard'; // 'standard' | 'custom' | 'free'

#[Assert\PositiveOrZero(message: 'Cena nemůže být záporná.')]
#[Assert\LessThanOrEqual(value: 15000, message: 'Maximální měsíční cena je 15 000 Kč (zákonný strop pro opakované platby).')]
public ?float $customMonthlyPriceInCzk = null;

public bool $isExternallyPrepaid = false;
public ?\DateTimeImmutable $paidThroughDate = null;
```

`#[Assert\Callback]` validations:
- `monthlyPriceMode === 'custom'` → `customMonthlyPriceInCzk` required, > 0.
- `isExternallyPrepaid === true` → `paidThroughDate` required, ≥ `startDate`, ≤ `endDate` if rentalType LIMITED.
- For LIMITED rental: `paidThroughDate ≤ endDate` (cannot prepay past contract end).

Form-type renders:
- A radio group "Cenový model": Standardní (1 500 Kč/měsíc) / Individuální / Zdarma. The Standardní label dynamically reflects the selected storage's effective price (rendered server-side after Stimulus updates on storage change — for v1 we show a static hint label and rely on the customer's confirmation on signing). Stimulus polish is **out of scope**.
- A conditional `customMonthlyPriceInCzk` numeric input, shown when `monthlyPriceMode === 'custom'` (CSS toggle via Stimulus controller; mirror existing `data-controller="visibility"` pattern if one exists, otherwise just `class="hidden"` toggled by a tiny inline controller in the template).
- A checkbox "Externí předplatné — zákazník již zaplatil externě" with a conditional date picker `paidThroughDate` (Flatpickr — already global per spec 007).
- An info paragraph explaining: *"Po vypršení předplatného bude zákazníkovi 7 dní předem zaslán e-mail s žádostí o nastavení automatické platby."*

The controller `AdminCreateOnboardingController` translates `monthlyPriceMode` + `customMonthlyPriceInCzk` into `individualMonthlyAmount` (halere) before constructing the command:

```php
$individualMonthlyAmount = match ($formData->monthlyPriceMode) {
    'standard' => null,
    'custom'   => (int) round($formData->customMonthlyPriceInCzk * 100),
    'free'     => 0,
};
$paidThroughDate = $formData->isExternallyPrepaid ? $formData->paidThroughDate : null;
```

If `monthlyPriceMode === 'free'`, force `paymentMethod = PaymentMethod::EXTERNAL` (already enforced in handler — but also clarify in the form by hiding the paymentMethod radio group when free).

### 6. AdminMigrateCustomer — mirror the same model

`AdminMigrateCustomerFormData` / `FormType` / `Command` / `Handler`: add the same `monthlyPriceMode` + `customMonthlyPriceInCzk` + `isExternallyPrepaid` + `paidThroughDate` fields.

**Migrate-specific reframing**: today migrate has `totalPriceInCzk` (lump sum) and `paidAt`. With the new model, the lump sum is the customer's external prepayment that covered them through `paidThroughDate`. Migrate becomes the **paper-form equivalent** of "external prepaid + recurring afterwards", which is exactly the new common shape.

In the migrate form:

- The `totalPriceInCzk` field is **renamed in the UI** to "Zaplacená částka externě" and stays on the form as a separate concept from the monthly recurring rate (it is recorded as the initial Payment, see below).
- `paidThroughDate` becomes **required** for migrate (was implicit before — it was equal to `endDate` of the contract). Default-fill it to `endDate` for LIMITED rentals; the admin can override.
- `monthlyPriceMode` defaults to `standard`. Admin can pick `custom` if the legacy customer pays a different rate going forward, or `free` if zero (rare for migrate but possible).

`AdminMigrateCustomerHandler` changes:

- Stop calling `Order::overrideFirstPaymentPrice($command->totalPrice)` — the order's locked-in monthly is now `individualMonthlyAmount ?? storageMonthly`, not the lump sum.
- Continue calling `OrderService::confirmPayment($order, $command->paidAt)` — this still fires `OrderPaid` and creates a Payment record for the lump sum via `RecordPaymentOnOrderPaidHandler`. The Payment amount **must equal the lump sum**, not the monthly. To preserve this without conflating the order's monthly:
  - Either: keep the override-then-restore dance (override `firstPaymentPrice` to lump sum just before `confirmPayment`, restore to monthly right after).
  - Or, cleaner: pass the lump-sum amount to `confirmPayment()` and have `RecordPaymentOnOrderPaidHandler` honour an explicit override.
  - **Recommended**: cleaner option. Add `\DateTimeImmutable $now` and `?int $explicitAmount = null` to `OrderService::confirmPayment()` and propagate via a new `OrderPaid::$amountOverride` field. `RecordPaymentOnOrderPaidHandler` uses `$event->amountOverride ?? $order->firstPaymentPrice`. **Audit every existing call site** of `confirmPayment` and verify the new arg is null.
- After contract creation, call the same `applyIndividualMonthlyAmount()` + `markExternallyPrepaid()` methods.
- The contract's `paidThroughDate` should be the customer's prepayment-end (typically equal to the migrate form's `paidThroughDate`); if absent (open-ended migrate where lump sum covers an open-ended period — uncommon), default to `endDate`.

### 7. Free contracts: skip invoice issuance

`src/Event/IssueInvoiceOnPaymentHandler.php` — guard:

```php
public function __invoke(OrderPaid $event): void
{
    $order = $this->orderRepository->get($event->orderId);

    if (0 === $order->firstPaymentPrice) {
        // Free contract — no invoice. Same rationale as the cron's
        // `if ($amount <= 0) return;` guard. See spec 025.
        return;
    }

    if (null !== $this->invoiceRepository->findByOrder($order)) {
        return;
    }
    ...
}
```

The cron's `ChargeRecurringPaymentHandler:50` already returns early when `$amount <= 0`, so free contracts are never charged downstream — no further changes needed there.

`SelfBillingService` (commission/landlord billing) — verify it skips zero-priced payments naturally. If it sums `Payment.amount`, zero adds nothing — no change needed; document this as already-correct via a unit test.

### 8. Admin order list — badges + filter

`src/Controller/Admin/AdminOrderListController.php`:

- Read `filter` query param: `null | 'individual' | 'external' | 'ending' | 'free'`.
- Pass filter to a new `OrderRepository::findAdminFiltered(filter, page, perPage)` method that joins on `Contract` for the price/prepayment filters. For orders without a contract yet (RESERVED / AWAITING_PAYMENT / PAID), the badges should still appear based on `Order.individualMonthlyAmount` / `Order.paidThroughDate` — the same fields persist there. Filter logic uses `COALESCE(c.individualMonthlyAmount, o.individualMonthlyAmount)` and `COALESCE(c.paidThroughDate, o.paidThroughDate)`.
- 'ending' filter: `paidThroughDate` set AND `paidThroughDate ≤ now + 14 days` AND `paidThroughDate ≥ now - 1 day` (still effective or barely expired).

`templates/admin/order/list.html.twig`:

- Add filter strip above the table (mirror spec 023's pattern at `templates/portal/user/list.html.twig:10-15`):
  ```twig
  <div class="mb-4 flex items-center gap-2 flex-wrap">
      <a href="{{ path('admin_orders_list') }}" class="btn btn-sm {{ filter ? 'btn-ghost' : 'btn-primary' }}">Vše</a>
      <a href="{{ path('admin_orders_list', {filter: 'individual'}) }}" class="btn btn-sm {{ filter == 'individual' ? 'btn-warning' : 'btn-ghost' }}">Indiv. cena ({{ counts.individual }})</a>
      <a href="{{ path('admin_orders_list', {filter: 'external'}) }}" class="btn btn-sm {{ filter == 'external' ? 'btn-info' : 'btn-ghost' }}">Externí předplatné ({{ counts.external }})</a>
      <a href="{{ path('admin_orders_list', {filter: 'ending'}) }}" class="btn btn-sm {{ filter == 'ending' ? 'btn-error' : 'btn-ghost' }}">Předplatné brzy končí ({{ counts.ending }})</a>
      <a href="{{ path('admin_orders_list', {filter: 'free'}) }}" class="btn btn-sm {{ filter == 'free' ? 'btn-success' : 'btn-ghost' }}">Zdarma ({{ counts.free }})</a>
  </div>
  ```
- After the existing status badge in the row, add a small badge cluster:
  ```twig
  {% if order.individualMonthlyAmount is not null %}
      {% if order.individualMonthlyAmount == 0 %}
          <span class="badge badge-success badge-sm" title="Smlouva zdarma — bez fakturace">Zdarma</span>
      {% else %}
          <span class="badge badge-warning badge-sm" title="Individuální měsíční cena: {{ (order.individualMonthlyAmount/100)|number_format(0, ',', ' ') }} Kč">Indiv. cena</span>
      {% endif %}
  {% endif %}
  {% if order.paidThroughDate %}
      <span class="badge badge-info badge-sm" title="Externí platba do {{ order.paidThroughDate|date('d.m.Y') }}">Předplaceno do {{ order.paidThroughDate|date('d.m.Y') }}</span>
  {% endif %}
  ```

### 9. Admin user list — "Onboardovaný" badge + filter

`UserRepository::findOnboardedUserIds(\DateTimeImmutable $now, array $userIds): array` — given a slice of user IDs, return the subset that has at least one Order with `isAdminCreated = true`. Mirrors `ContractRepository::findOverdueUserIds()` exactly (`src/Repository/ContractRepository.php` — used by spec 023).

`UserListController`:
- Read `filter=onboarded` query param in addition to existing `filter=overdue`.
- Pass `onboardedIdSet = array_flip(...)` to template alongside `debtorIdSet`.

`templates/portal/user/list.html.twig`:
- Add filter chip alongside existing two: `Pouze onboardovaní ({{ onboardedUserCount }})`.
- In the Stav column, add after the Dlužník badge:
  ```twig
  {% if onboardedIdSet[user.id.toRfc4122()] is defined %}
      <span class="badge badge-secondary" title="Zákazník byl onboardován adminem">Onboardovaný</span>
  {% endif %}
  ```

### 10. Admin order detail — info banner

New partial `templates/admin/order/_onboarding_banner.html.twig`:

```twig
{% set hasIndividual = order.individualMonthlyAmount is not null and order.individualMonthlyAmount > 0 %}
{% set isFree = order.individualMonthlyAmount == 0 %}
{% set hasPrepaid = order.paidThroughDate is not null %}

{% if hasIndividual or isFree or hasPrepaid or order.isAdminCreated %}
    <div class="alert alert-info mb-4">
        <div class="flex flex-col gap-1 text-sm">
            {% if order.isAdminCreated %}<div>Tato objednávka byla vytvořena adminem (onboarding).</div>{% endif %}
            {% if isFree %}<div><strong>Smlouva zdarma</strong> — žádné účtování ani fakturace.</div>{% endif %}
            {% if hasIndividual %}<div><strong>Individuální měsíční cena:</strong> {{ (order.individualMonthlyAmount/100)|number_format(0, ',', ' ') }} Kč</div>{% endif %}
            {% if hasPrepaid %}<div><strong>Externě předplaceno do:</strong> {{ order.paidThroughDate|date('d.m.Y') }}</div>{% endif %}
            {% if order.paymentMethod %}<div><strong>Způsob platby:</strong> {{ order.paymentMethod.value == 'external' ? 'Externí (mimo GoPay)' : 'GoPay' }}</div>{% endif %}
        </div>
    </div>
{% endif %}
```

`templates/admin/order/detail.html.twig` — `{% include 'admin/order/_onboarding_banner.html.twig' %}` near the top (after the existing dlužník/overdue banner from spec 023).

### 11. New repo additions

`OrderRepository`:
- `countByAdminFilter(\DateTimeImmutable $now, ?string $filter): int` — returns total for the chosen filter (used for both pagination and the count badge in the filter strip).
- `findAdminFiltered(\DateTimeImmutable $now, ?string $filter, int $page, int $perPage): Order[]` — paginated.
- `countAllAdminFilters(\DateTimeImmutable $now): array{individual:int, external:int, ending:int, free:int}` — returns all four counts in one round trip for the filter-strip labels.
- `findExternalPrepaymentsEnding(\DateTimeImmutable $now, int $daysAhead): Contract[]` — actually belongs on `ContractRepository`; see below.

`ContractRepository`:
- `findExternalPrepaymentsEndingInRange(\DateTimeImmutable $rangeStart, \DateTimeImmutable $rangeEnd): Contract[]`. Definition:
  ```dql
  c.paidThroughDate IS NOT NULL
  AND c.goPayParentPaymentId IS NULL
  AND c.terminatedAt IS NULL
  AND c.paidThroughDate BETWEEN :rangeStart AND :rangeEnd
  AND (c.lastAdvanceNoticeSentAt IS NULL OR c.lastAdvanceNoticeSentAt < :rangeStart)
  ```
  Reuse `lastAdvanceNoticeSentAt` (already on Contract for the existing 6-month-gap notice) to prevent duplicate sends across cron runs.

`UserRepository`:
- `findOnboardedUserIds(array $userIds): array` — returns RFC-4122 strings of users with ≥1 admin-created order. Same shape as `ContractRepository::findOverdueUserIds()`.
- `countOnboarded(): int` — total onboarded users (for the filter chip badge).

### 12. New cron: `SendExternalPrepaymentEndingSoonCommand`

`src/Console/SendExternalPrepaymentEndingSoonCommand.php` — daily cron mirroring `SendRecurringPaymentAdvanceNoticeCommand` (`src/Console/SendRecurringPaymentAdvanceNoticeCommand.php`):

```php
#[AsCommand(
    name: 'app:send-external-prepayment-ending-soon',
    description: 'Notify customers 7 days before their external (non-GoPay) prepayment runs out, asking them to set up automatic payment.',
)]
final class SendExternalPrepaymentEndingSoonCommand extends Command
{
    private const int DAYS_AHEAD = 7;
    ...
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = $this->clock->now();
        $rangeStart = $now->setTime(0, 0, 0);
        $rangeEnd = $rangeStart->modify('+' . self::DAYS_AHEAD . ' days');

        $contracts = $this->contractRepository->findExternalPrepaymentsEndingInRange($rangeStart, $rangeEnd);
        // dispatch event per contract; $contract->recordAdvanceNoticeSent($now);
    }
}
```

Wire into the existing daily-cron schedule (the deployment uses cron / supervisor config — verify in `config/` for the existing patterns of `app:send-recurring-payment-advance-notice`).

### 13. New event + handler + email

`src/Event/ExternalPrepaymentEndingSoon.php` — `final readonly` DTO:

```php
public function __construct(
    public Uuid $contractId,
    public \DateTimeImmutable $occurredOn,
) {}
```

`src/Event/SendExternalPrepaymentEndingSoonEmailHandler.php` — mirrors `SendRecurringPaymentAdvanceNoticeEmailHandler`:

- Loads contract.
- Renders a customer email (`templates/email/external_prepayment_ending_soon.html.twig` + `.txt.twig`):
  - Subject: "Vaše předplatné brzy končí — nastavte automatickou platbu"
  - Body: explains the prepayment end date, current monthly amount (= `getEffectiveMonthlyAmount()`), and asks the customer to **contact admin** (we have no self-service portal yet). Include the public order-status permalink (`OrderStatusUrlGenerator`) so the customer has full context.
  - For free contracts: skip — there's nothing for the customer to do. Guard inside the handler: `if ($contract->isFree()) return;`.
- Sends a courtesy admin CC to a fixed admin address (reuse the pattern from `SendRecurringPaymentAdvanceNoticeEmailHandler` if it CCs admin; otherwise add a plain admin notification with subject "Externí předplatné brzy končí — {customer name}").
- Calls `$contract->recordAdvanceNoticeSent($now)` to dedupe.

### 14. Override behaviour for `OverdueChecker`

`src/Service/Overdue/OverdueChecker.php:100` — `$monthlyRate = $contract->order->firstPaymentPrice;` already does the right thing because `Order.firstPaymentPrice` is the locked-in monthly **after this spec lands**. **No change** needed for individual-priced contracts.

For **free** contracts: `Order.firstPaymentPrice = 0`, so `overdueAmount = 0`. Filter them out of the overdue list (a zero-amount overdue is meaningless):

```php
// In OverdueChecker::findOverdueViews(), drop free contracts before mapping
$contracts = array_values(array_filter(
    $this->contractRepository->findWithPaymentIssues($now),
    static fn (Contract $c): bool => !$c->isFree(),
));
```

### 15. Admin nav: keep existing onboarding hub

No change to nav. The existing "Onboarding" entry in `templates/portal/layout.html.twig:116/311` already points at the hub; the two card pages (digital + migrate) both gain the new fields.

### 16. Tests

Place tests according to existing conventions (`tests/Unit/` for pure logic; `tests/Integration/` for repo/handler with DB).

#### Unit

- `tests/Unit/Entity/ContractTest.php` (new): `getEffectiveMonthlyAmount` — returns override when set, storage default when null; `applyIndividualMonthlyAmount` — rejects negative, rejects over-cap; `isFree` — true only for explicit zero (not null); `markExternallyPrepaid` — sets paidThroughDate + nextBillingDate = +1 day.
- `tests/Unit/Entity/OrderOnboardingTest.php` (extend existing): `setOnboardingBillingTerms` writes the two new fields; subsequent calls overwrite (write-once is enforced at the **command level**, not entity level — keep entity simple).
- `tests/Unit/Service/PriceCalculatorIndividualPriceTest.php` (new): `buildScheduleFromOrder` — verify the schedule respects `Order.firstPaymentPrice` (already true; pin it).

#### Integration

- `tests/Integration/Command/ChargeRecurringPaymentHandlerTest.php` (extend existing):
  - **Critical**: contract with `individualMonthlyAmount = 50_000` (500 Kč) charges 500 Kč every month even when storage price is 1 500 Kč. (Mock GoPay client to capture the amount passed.)
  - Free contract (`individualMonthlyAmount = 0`): handler returns early without calling GoPay (the early return at line 50 fires).
  - Override = null: charges storage price (current behaviour, regression guard).
- `tests/Integration/Command/AdminCreateOnboardingHandlerTest.php` (new):
  - Custom monthly: `Order.firstPaymentPrice` = override; `Order.individualMonthlyAmount` = override; `Order.paidThroughDate` = command's value.
  - Free + paymentMethod GOPAY in form: handler forces EXTERNAL.
  - Prepaid set + paymentMethod GOPAY in form: handler forces EXTERNAL.
- `tests/Unit/Command/AdminMigrateCustomerHandlerTest.php` (extend existing):
  - `paidThroughDate` defaults to `endDate` when omitted in command for LIMITED rentals.
  - Lump sum recorded as Payment.amount (via the new `OrderPaid::amountOverride`); contract's monthly stays at storage default unless `individualMonthlyAmount` set.
  - Custom monthly + lump sum: Payment.amount = lump sum, Contract.individualMonthlyAmount = custom monthly, Order.firstPaymentPrice = custom monthly.
- `tests/Integration/Repository/ContractRepositoryTest.php` (extend): `findExternalPrepaymentsEndingInRange` — selects contracts whose `paidThroughDate` falls in the window AND no GoPay setup AND not terminated AND not noticed since rangeStart.
- `tests/Integration/Repository/OrderRepositoryTest.php` (extend): `findAdminFiltered` for each filter; `countAllAdminFilters` returns all four.
- `tests/Integration/Repository/UserRepositoryTest.php` (extend): `findOnboardedUserIds` — returns only IDs with admin-created orders; `countOnboarded`.
- `tests/Integration/Console/SendExternalPrepaymentEndingSoonCommandTest.php` (new): runs cron, asserts events dispatched only for matching contracts; reruns same day → no duplicate dispatch (advance-notice dedupe).
- `tests/Integration/Service/Overdue/OverdueCheckerTest.php` (extend existing): free contracts are excluded from overdue views; external-prepaid-but-not-yet-expired contracts are excluded; external-prepaid-expired-without-GoPay contracts are included with severity WARNING (treat as standard "Strhnutí splatné").

#### Fixtures

`fixtures/StorageFixtures.php` is already on disk per `git status`. Add to `OrderFixtures` / `ContractFixtures` (or add a new `OnboardingFixtures.php`):

- One contract for `landlord@example.com`'s tenant with `individualMonthlyAmount = 80_000` (800 Kč) — to surface "Indiv. cena" badge in dev.
- One free contract (`individualMonthlyAmount = 0`) — to surface "Zdarma" badge.
- One external-prepaid contract with `paidThroughDate = MockClock::today() + 5 days` — to surface "Předplatné brzy končí" filter and trigger the cron.
- One external-prepaid contract with `paidThroughDate = MockClock::today() - 10 days, goPayParentPaymentId = NULL` — to verify it appears in Po splatnosti (spec 023 integration).

### 17. PROJECT_MAP.md update

Append to `.claude/specs/PROJECT_MAP.md`:

- Routes — no change.
- Entities — add `Contract.individualMonthlyAmount`, `Order.individualMonthlyAmount`, `Order.paidThroughDate` to the entity descriptions.
- Commands — no new commands; mention extended fields on `AdminCreateOnboardingCommand` + `AdminMigrateCustomerCommand`.
- Domain Events — add `ExternalPrepaymentEndingSoon`.
- Console commands — add `app:send-external-prepayment-ending-soon`.
- Services — no new top-level services.

## Acceptance

- [ ] `docker compose exec web composer quality` is green (cs:fix, phpstan level 8, test:unit, test).
- [ ] `docker compose exec web bin/console doctrine:schema:validate` reports no diff after the new migration.
- [ ] `docker compose exec web bin/console make:migration` was used to generate the migration; no handwritten DDL.
- [ ] **Locked-in monthly** — a contract created with `individualMonthlyAmount = 50_000` charges 500 Kč in month 1, 2, 3, … even after the storage's `pricePerMonth` is updated to 2 000 Kč. (Integration test asserts the GoPay client receives 50 000 halere on every recurring call.)
- [ ] **Free** — a contract with `individualMonthlyAmount = 0` is never charged (no GoPay call), no invoice is issued (`InvoiceRepository::findByOrder` returns null after `OrderPaid`), and it does not appear in `OverdueChecker::findOverdueViews()` even after `nextBillingDate` would otherwise have flagged it.
- [ ] **External prepayment** — a digital onboarding with `paidThroughDate = 2026-12-31` produces a contract with `paidThroughDate = 2026-12-31`, `nextBillingDate = 2027-01-01`, `goPayParentPaymentId = NULL`. Cron does not attempt to charge before 2027-01-01. From 2027-01-02 the contract appears in `/portal/admin/po-splatnosti`.
- [ ] **Reminder cron** — running `app:send-external-prepayment-ending-soon` on a day where ≥1 contract has `paidThroughDate` within +7 days dispatches `ExternalPrepaymentEndingSoon` and the customer email lands in the test mailer; running it again the same day is idempotent (no duplicate dispatch — `lastAdvanceNoticeSentAt` guard).
- [ ] **Admin order list** — visiting `/portal/admin/orders?filter=individual` lists only orders with non-null individualMonthlyAmount; `?filter=ending` lists only those with `paidThroughDate ≤ now+14d AND ≥ now-1d`. Filter chip count badges match list lengths. Row badges render as designed. **Verified manually in the browser** (this is a UI change — not just type checking).
- [ ] **Admin user list** — `/portal/users?filter=onboarded` lists only users with admin-created orders; "Onboardovaný" badge renders next to "Dlužník" / "Ověřený" badges. Verified in browser.
- [ ] **Admin order detail** — `_onboarding_banner.html.twig` renders for every onboarded order, hides for vanilla customer-created orders. Verified in browser.
- [ ] **Form regression** — submitting digital onboarding with `monthlyPriceMode = standard` + no prepayment behaves identically to today (`Order.firstPaymentPrice = storage.effectivePricePerMonth`, `individualMonthlyAmount IS NULL`, `paidThroughDate IS NULL`).
- [ ] **Migrate regression** — submitting migrate with monthly = standard and prepaidThroughDate defaulting to endDate produces the same Payment record (lump-sum amount) as today.
- [ ] **Compliance cap** — entering 16 000 Kč in the custom monthly field is rejected at form-validation time (`LessThanOrEqual(15000)`); attempting it via direct entity call throws `\DomainException`.
- [ ] PROJECT_MAP.md and BACKLOG.md updated.

## Out of scope

- **Customer self-service portal flow to set up GoPay after prepayment ends.** Today the customer reads the reminder email and contacts the admin, who either extends `paidThroughDate` (manually) or sends them a payment link from elsewhere. A dedicated controller `/portal/objednavky/{id}/nastavit-platbu` that reuses GoPay token-capture deserves its own spec — touches `GoPayClient`, requires deciding between min-charge verification vs. true preauthorization, and needs its own UX for the post-success state. **→ defer to spec 026.**
- **Bulk admin actions** ("extend prepayment for all expiring contracts in November"). Out — admins can do it one at a time via the order edit page (separate spec).
- **Custom-price audit/history.** No "price changed from X to Y on date Z by user W" log. The current value lives on the Contract; if you need a history, a follow-up spec adds a `ContractPriceChange` entity. Outside of this spec.
- **Stimulus polish on the form** (live-update the "Standardní cena" hint when storage selection changes; live-toggle the conditional fields with a smooth animation). Static label + simple show/hide is fine for v1.
- **Translating the new fields into customer-visible PDFs.** The contract template already renders the locked-in monthly via `Order.firstPaymentPrice`; that now holds the individual price, so the PDF reflects it automatically. No template surgery needed.
- **Multi-currency.** Halere only.
- **Allowing GoPay onboarding with a custom monthly that differs from the first GoPay charge.** GoPay-paid onboardings can use individual price (the customer pays the individual price every month including the first). This works because `Order.firstPaymentPrice` is the same for both the GoPay first charge and the recurring schedule.
- **Renaming the migrate "totalPrice" field to "lumpSumPrepayment" everywhere.** UI label changes per req. 6; internal class names stay (`AdminMigrateCustomerCommand::$totalPrice`) to keep the diff small.

## Open questions

None — proceed.
