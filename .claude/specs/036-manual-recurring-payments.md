# 036 — Manual recurring payments (per-cycle GoPay link instead of card-on-file token)

**Status:** done
**Type:** feature (billing, customer choice, compliance-sensitive)
**Scope:** large (~30 files: new entity + repo + 1 enum, 2 new crons, 4 new email templates + handlers, 1 new event + 1 reused, 1 new service, 2 form changes, 1 Live Component update, 2 webhook-handler branches, MRR query refactor across 7 methods, view-model updates, billing-status partial, audit logger, ~12 new tests)
**Depends on:** none (builds on 025 + 030)

## Problem

Today every rental ≥ 28 days establishes a GoPay ON_DEMAND recurring token at signing — the customer enters their card once and we auto-charge every month. Some customers won't or can't do that: they don't want their card stored, or their corporate card forbids saved-credential charges, or they simply prefer to approve each payment.

For these customers the only current alternative is "External payment" via admin onboarding — i.e. bank transfer or cash, with the admin manually reconciling. That doesn't scale and leaves the customer outside the GoPay flow they expect.

## Goal

A second self-service billing track for **fixed-term LIMITED rentals ≥ 28 days**: the customer pays the first month online (same as today, but one-time — no token saved) and from month 2 onward receives an e-mail with a one-time GoPay payment link, 7 days before each new period. Clicks → pays → invoice issues exactly as for the AUTO path.

If the customer doesn't pay, two reminder e-mails go out at d-2 and d-0, then the contract enters the existing overdue treatment (admin dashboard + daily digest) with two more nag e-mails to the customer at d+3 and d+7. Both the consumer order form and the admin onboarding form expose the choice. Unlimited rentals stay on AUTO recurring (an open-ended manual cadence has no natural end).

## Context (current state)

### Today's three pricing modes

`PriceCalculator::needsRecurringBilling()` (`src/Service/PriceCalculator.php:165-172`) splits rentals into:

1. **One-time** (`Order::isOneTime()` — `src/Entity/Order.php:302-305`): LIMITED < 28 days → single `GoPayClient::createPayment()` call, no recurring setup.
2. **Fixed-term recurring** (`Order::isFixedTermRecurring()` — `src/Entity/Order.php:297-300`): LIMITED ≥ 28 days → `GoPayClient::createRecurringPayment()` (ON_DEMAND), first payment establishes `goPayParentPaymentId` on `Contract`; cron `app:process-recurring-payments` (`src/Console/ProcessRecurringPaymentsCommand.php`) walks calendar months until end-date; last cycle prorated by `RecurringAmountCalculator` (`src/Service/Billing/RecurringAmountCalculator.php`).
3. **Unlimited recurring** (`Order::isUnlimited()`): UNLIMITED → same ON_DEMAND flow but open-ended.

The 28-day threshold (`PriceCalculator::WEEKLY_THRESHOLD_DAYS`) is **the eligibility boundary** for the new MANUAL track. The Order entity already pins its own copy of `>= 28 days` in `Order::isRecurring()` with a comment that a unit test keeps the two in sync.

### Existing recurring lifecycle (read this before editing)

- **First payment**: `InitiatePaymentHandler` (`src/Command/InitiatePaymentHandler.php:34-48`) forks on `needsRecurringBilling`. Recurring path sets `recurrence_cycle: ON_DEMAND, recurrence_date_to: '2099-12-31'` in `GoPayApiClient::createRecurringPayment()` (`src/Service/GoPay/GoPayApiClient.php:36-53`).
- **Token capture**: webhook lands → `ProcessPaymentNotificationHandler::__invoke()` (`src/Command/ProcessPaymentNotificationHandler.php:74-104`): if `needsRecurring`, calls `Order::setGoPayParentPaymentId()` and dispatches `RecurringPaymentEstablished` (drives the Podmínky čl. IV confirmation e-mail). Then `confirmPayment` + `CompleteOrderCommand`.
- **Contract creation**: `OrderService::completeOrder()` (`src/Service/OrderService.php:141-182`) copies `Order.individualMonthlyAmount` / `Order.paidThroughDate` onto the Contract.
- **Recurring setup persistence**: `RecurringPaymentEstablished` does NOT itself write `Contract.goPayParentPaymentId` — that happens in `Contract::setRecurringPayment()` called from... actually `Order::goPayParentPaymentId` is set on Order, then `Contract::setRecurringPayment()` is called by an event handler — see how the existing flow propagates the token to `Contract.goPayParentPaymentId`. (Implementer: grep `setRecurringPayment` to confirm the propagation site before re-using or branching it.)
- **Monthly charge**: `app:process-recurring-payments` (`src/Console/ProcessRecurringPaymentsCommand.php`) → `ChargeRecurringPaymentCommand` → `GoPayClient::createRecurrence($parentId, $amount, $orderNumber, $description)` (`src/Command/ChargeRecurringPaymentHandler.php:96-101`). 15× 2 s polling for synchronous confirmation; in-flight reconciliation via `Contract::$pendingRecurringPaymentId` (`src/Entity/Contract.php:58-59`).
- **Failure / retry**: `ChargeRecurringPaymentHandler` throws → `ProcessRecurringPaymentsCommand::recordFailure()` increments `failedBillingAttempts` and dispatches `RecurringPaymentFailed`. Retry cron `app:retry-failed-payments` uses `ContractRepository::findNeedingRetry()` — 3-day then 7-day ladder; after attempt 2, no more retries (contract sits in overdue).
- **Customer cancel**: `/opakovana-platba/{contractId}/zrusit` → `CancelRecurringPaymentController` (only when `Contract::hasActiveRecurringPayment()` — guard at line 37). Calls `GoPayClient::voidRecurrence()` and `Contract::cancelRecurringPayment()`.

### Existing overdue infrastructure (reused as-is for MANUAL)

- `OverdueChecker::findOverdueViews()` (`src/Service/Overdue/OverdueChecker.php:25-41`) catches contracts where `failedBillingAttempts > 0` **OR** `nextBillingDate < now-1day` — MANUAL falls naturally into the second branch.
- Severity is WARNING ("Strhnutí splatné") for first day overdue, ERROR ("Selhání platby (Nx)") after retries. For MANUAL we surface a distinct reason label.
- Admin dashboard tile, `/portal/admin/po-splatnosti` list, daily digest cron `app:send-overdue-digest-email` (spec 031), badges across user/order lists — all auto-pick-up.

### Existing onboarding form (already has 3 cenový-model knobs, ADDS one more)

`AdminCreateOnboardingFormType` (`src/Form/AdminCreateOnboardingFormType.php`) and matching `AdminCreateOnboardingFormData` (`src/Form/AdminCreateOnboardingFormData.php`) already split:

- `paymentMethod` (EXTERNAL / GOPAY): decides how the **first** payment is collected (cash/wire vs GoPay link sent with the signing email).
- `monthlyPriceMode` (standard / custom / free).
- `isExternallyPrepaid` + `paidThroughDate`.

The new `billingMode` is **orthogonal** to `paymentMethod` — it decides the SUBSEQUENT cadence (token vs link). All four combinations are valid; see "Onboarding" section below.

### MRR / "active recurring" predicates currently filtered on token

The following methods filter on `c.goPayParentPaymentId IS NOT NULL` (i.e. "has GoPay token") as a proxy for "is on a recurring billing track" — this proxy breaks for MANUAL contracts and must be widened (see Requirement 9):

- `ContractRepository::findWithActiveRecurringByLandlord` (`src/Repository/ContractRepository.php:733-746`)
- `ContractRepository::sumExpectedRecurringByLandlord` (line 754)
- `ContractRepository::sumExpectedRecurringAll` (line 777)
- `ContractRepository::countActiveRecurringByLandlord` (line 797)
- `ContractRepository::countActiveRecurringAll` (line 814)
- `ContractRepository::countActiveRecurringAtPlace` (line 879)
- `ContractRepository::sumExpectedRecurringAtPlace` (line 922)
- `ContractRepository::loadContractStatsByPlaceIds` (line 840 — raw SQL)

`ContractRepository::loadCustomerStatsByUserIds` (line 593) already uses the **shape** predicate (`endDate IS NULL OR endDate-startDate >= 28`) and does not need the rewrite — but should pick MANUAL up automatically once `billingMode` exists, since it already does the right thing.

### Verified GoPay capability (no new API required)

`GoPayApiClient::createPayment()` (lines 23-34) creates a one-time payment without recurrence — exactly what we need for both the first MANUAL payment and each monthly link. We extract the parametrised `buildPaymentData()` into a primitive that doesn't require an `Order` so the MANUAL cron can call it for a Contract+period (Requirement 5).

`Payments::getStatus()` is already polled via `GoPayClient::getStatus()` for the AUTO reconciliation path — the same call works to confirm a MANUAL payment from the webhook.

### Compliance constraints (READ BEFORE TOUCHING ORDER-ACCEPT TEMPLATE)

Per `.claude/COMPLIANCE.md` and Podmínky opakovaných plateb:

- **Recurring-payment consent checkbox** (`templates/public/order_accept.html.twig:461-470` — "Souhlasím s opakovanou platbou") MUST be hidden for MANUAL orders. There is no token to save and no automated debit — the master T&C consent already covers each one-time payment. Leaving the recurring consent visible for MANUAL would imply consent to something the customer is not actually agreeing to (GoPay rule + Podmínky čl. I).
- **"Parametry opakované platby" disclosure card** (`templates/public/order_accept.html.twig:385-460`) also hides for MANUAL — those parameters (max amount, debit day, cancellation contact) describe the token-based track only.
- **Submit button** stays exactly `OBJEDNÁVÁM a zaplatím` (§ 1826a OZ — unchanged).
- **Identification block, card+3DS+GoPay logos, vč. DPH** — all unchanged.
- The 7-business-day **advance notice** cron (`app:send-recurring-payment-advance-notice`, spec) applies only to AUTO recurring (Podmínky čl. V is specifically about silent debits after long gaps). MANUAL is exempt — each charge is explicitly approved.

### Customer billing-status partial (spec 030)

`templates/components/customer_billing_status.html.twig` renders one of three states today: free, externally-prepaid-future, externally-prepaid-ending-soon. We add **two more variants for MANUAL** (Requirement 7) so the customer sees their cadence on `/portal/objednavky/{id}` and `/stav`.

## Architecture

```
Customer flow (LIMITED, ≥ 28 days)
─────────────────────────────────
order create  ───► [radio: AUTO | MANUAL]  ───► order accept  ───► /platba (first month)
                          │                          │
                          │                          └─► recurring-consent CARD shown only if AUTO
                          │
                          ▼
                  Order.billingMode persisted
                  carried into Contract.billingMode at completion


AUTO recurring (today, unchanged behaviour)
────────────────────────────────────────────
month 1 payment ─► ON_DEMAND token established ─► cron charges card monthly


MANUAL recurring (new)
──────────────────────
month 1 payment ─► no token, contract.billingMode = MANUAL_RECURRING

month 2..N (per cycle, anchored on contract.nextBillingDate)
   d-7  ─► cron creates GoPay one-time payment, sends "platba splatná za 7 dní" e-mail with gw_url
   d-2  ─► cron sends "připomenutí" e-mail (same gw_url unless GoPay timed it out)
   d-0  ─► cron sends "splatné dnes" e-mail
   d+1+ ─► contract appears in admin Po splatnosti queue (existing OverdueChecker)
   d+3  ─► cron sends "po splatnosti" e-mail to customer
   d+7  ─► cron sends final "po splatnosti" e-mail; from here, admin handles manually

Webhook arrives ─► ProcessPaymentNotificationHandler reconciles via ManualPaymentRequest
   ─► Contract.recordBillingCharge() (same method AUTO uses)
   ─► RecurringPaymentCharged event (issues invoice via existing handler)
```

## Requirements

### 1. New `BillingMode` enum

Create `src/Enum/BillingMode.php`:

```php
<?php
declare(strict_types=1);
namespace App\Enum;

enum BillingMode: string
{
    case ONE_TIME = 'one_time';                // LIMITED < 28 days — single payment, no further cycles
    case AUTO_RECURRING = 'auto_recurring';    // GoPay ON_DEMAND token, cron auto-charges
    case MANUAL_RECURRING = 'manual_recurring'; // No token, cron emails one-time payment links

    public function isRecurring(): bool
    {
        return self::ONE_TIME !== $this;
    }

    public function label(): string
    {
        return match ($this) {
            self::ONE_TIME => 'Jednorázová platba',
            self::AUTO_RECURRING => 'Automatická platba kartou',
            self::MANUAL_RECURRING => 'Ručně schvalovaná platba (výzva e-mailem)',
        };
    }
}
```

Register a Twig global for the enum class so templates can switch on cases without needing a controller pass-through (mirrors existing `PaymentFrequency` access patterns).

### 2. Persist `billingMode` on `Order` and `Contract`

Add to `src/Entity/Order.php` (next to `paymentMethod`):

```php
#[ORM\Column(length: 20, enumType: BillingMode::class)]
public private(set) BillingMode $billingMode = BillingMode::AUTO_RECURRING;
```

Setter:

```php
public function setBillingMode(BillingMode $mode): void
{
    $this->billingMode = $mode;
}
```

Default deliberately `AUTO_RECURRING` so existing creation paths that don't yet set the field behave identically. The order create / onboarding flows set it explicitly.

Add to `src/Entity/Contract.php` (next to `individualMonthlyAmount`):

```php
#[ORM\Column(length: 20, enumType: BillingMode::class)]
public private(set) BillingMode $billingMode = BillingMode::AUTO_RECURRING;
```

Setter applied from `OrderService::completeOrder()` (Requirement 4).

**Migration (auto-generated via `make:migration`):**

- New columns on both tables, default `'auto_recurring'`.
- Backfill DML for `contract.billing_mode`:
  - `'one_time'` WHERE `(end_date - start_date) < 28` (the one-shot case — but it stays AUTO-default-safe because such contracts never re-bill anyway; classifying them correctly is hygiene for the predicate in Requirement 9).
  - `'auto_recurring'` everywhere else (the safe-default — every pre-existing recurring contract was AUTO).
- Same backfill on `orders.billing_mode` mirroring the shape: derive from `(end_date - start_date) >= 28 OR end_date IS NULL` → `auto_recurring` else `one_time`.

A unit test in `tests/Unit/Entity/OrderBillingModeTest.php` pins `Order::isRecurring()` against `BillingMode::isRecurring()` shape so the duplicated 28-day boundary stays in sync.

### 3. New `ManualPaymentRequest` entity + repository

`src/Entity/ManualPaymentRequest.php`:

```php
<?php
declare(strict_types=1);
namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'manual_payment_request')]
#[ORM\UniqueConstraint(name: 'uniq_manual_payment_request_contract_period', columns: ['contract_id', 'period_start'])]
class ManualPaymentRequest
{
    #[ORM\Column(length: 20)]
    public private(set) string $status; // 'pending' | 'paid' | 'cancelled' | 'expired'

    #[ORM\Column(nullable: true)]
    public private(set) ?string $goPayPaymentId = null;

    #[ORM\Column(length: 1000, nullable: true)]
    public private(set) ?string $goPayGatewayUrl = null;

    /** Tracks which reminder stages have already e-mailed. Keys: 'initial','d_minus_2','d_zero','d_plus_3','d_plus_7'. Values: ISO timestamp. */
    #[ORM\Column(type: Types::JSON, options: ['default' => '{}'])]
    public private(set) array $sentStages = [];

    #[ORM\Column(nullable: true)]
    public private(set) ?\DateTimeImmutable $paidAt = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME, unique: true)]
        private(set) Uuid $id,
        #[ORM\ManyToOne(targetEntity: Contract::class)]
        #[ORM\JoinColumn(nullable: false)]
        private(set) Contract $contract,
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private(set) \DateTimeImmutable $periodStart,
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private(set) \DateTimeImmutable $periodEnd,
        #[ORM\Column]
        private(set) int $amount,             // halere
        #[ORM\Column]
        private(set) \DateTimeImmutable $createdAt,
    ) {
        $this->status = 'pending';
    }

    public function attachGoPayPayment(string $paymentId, string $gatewayUrl): void { /* ... */ }
    public function recordStageSent(string $stage, \DateTimeImmutable $now): void { /* ... */ }
    public function markPaid(\DateTimeImmutable $now): void { /* ... */ }
    public function markExpired(\DateTimeImmutable $now): void { /* ... */ }
    public function hasStageSent(string $stage): bool { /* ... */ }
}
```

`src/Repository/ManualPaymentRequestRepository.php` — composition over `EntityManagerInterface` (no `ServiceEntityRepository`, no `flush()`). Methods:

- `save(ManualPaymentRequest $request): void`
- `get(Uuid $id): ManualPaymentRequest` (throws `ManualPaymentRequestNotFound`)
- `findByContractAndPeriod(Contract $contract, \DateTimeImmutable $periodStart): ?ManualPaymentRequest`
- `findByGoPayPaymentId(string $paymentId): ?ManualPaymentRequest`
- `findPendingForCurrentCycle(Contract $contract, \DateTimeImmutable $now): ?ManualPaymentRequest` — used by the view model to expose a "Zaplatit nyní" link

Add `App\Exception\ManualPaymentRequestNotFound`.

### 4. Carry billingMode through order completion

In `src/Service/OrderService.php` `completeOrder()` (around line 156), after creating the Contract:

```php
$contract->applyBillingMode($order->billingMode);
```

Add `Contract::applyBillingMode(BillingMode $mode): void` setter. No event needed — billing mode is locked at creation and never changes (per design decision).

### 5. Refactor `GoPayClient::createPayment` to expose a Contract-aware variant

The current `GoPayApiClient::buildPaymentData()` (lines 100-127) takes an `Order` to derive description and order number. Refactor:

```php
// New primitive on the interface
public function createOneTimeCharge(
    int $amount,
    string $orderNumber,
    string $orderDescription,
    string $payerEmail,
    string $returnUrl,
    string $notificationUrl,
): GoPayPayment;
```

`createPayment(Order $order, ...)` becomes a thin wrapper around `createOneTimeCharge` so the existing Order-based call site doesn't change. The new MANUAL cron calls `createOneTimeCharge` directly with:

- `orderNumber = sprintf('MNL-%s-%s', $contract->id->toRfc4122(), $periodStart->format('Ymd'))`
- `orderDescription = sprintf('Pronájem skladu %s - %s (%s)', $storage->number, $storageType->name, $periodStart->format('m/Y'))`
- `payerEmail = $contract->user->email`
- `returnUrl = OrderStatusUrlGenerator::generate($contract->order)` — signed status permalink
- `notificationUrl = url('public_gopay_notification')` — existing endpoint

The `GoPayClient` interface (`src/Service/GoPay/GoPayClient.php`) gets the new method; the in-memory test double `tests/Integration/...GoPayClient` (if it exists) gets the same method.

### 6. Per-place reminder-schedule config (locked into the order at creation)

Schedule offsets are operator-managed **per Place** and snapshotted onto each Order at creation, so changes to a Place's schedule never retroactively shift the cadence for a running rental. Mirrors the pattern already used by `Place.orderExpirationDays` (`src/Entity/Place.php:38`) and `Place.storageCode*`.

**`src/Entity/Place.php`** — five new flat `int` columns next to `orderExpirationDays`:

```php
#[ORM\Column(options: ['default' => -7])]
public private(set) int $manualBillingOffsetInitial = -7;

#[ORM\Column(options: ['default' => -2])]
public private(set) int $manualBillingOffsetReminder = -2;

#[ORM\Column(options: ['default' => 0])]
public private(set) int $manualBillingOffsetFinalDue = 0;

#[ORM\Column(options: ['default' => 3])]
public private(set) int $manualBillingOffsetOverdueFirst = 3;

#[ORM\Column(options: ['default' => 7])]
public private(set) int $manualBillingOffsetOverdueFinal = 7;
```

Behaviour method (same shape as `updateStorageCodeConfig` at line 150):

```php
public function updateManualBillingSchedule(
    int $initial,
    int $reminder,
    int $finalDue,
    int $overdueFirst,
    int $overdueFinal,
    \DateTimeImmutable $now,
): void {
    $this->manualBillingOffsetInitial = $initial;
    $this->manualBillingOffsetReminder = $reminder;
    $this->manualBillingOffsetFinalDue = $finalDue;
    $this->manualBillingOffsetOverdueFirst = $overdueFirst;
    $this->manualBillingOffsetOverdueFinal = $overdueFinal;
    $this->updatedAt = $now;
}
```

Flat int columns (not JSON) — the offsets are semantically named, validation is easier with typed fields, form binding is trivial, and the existing Place schema is uniformly flat. Five separate fields is hygienic for the five well-known stages; if we ever need a configurable number of stages, swap to JSON in a follow-up.

**`src/Entity/Order.php`** — five mirror columns, populated at order creation as a snapshot of the Place's current settings:

```php
#[ORM\Column]
public private(set) int $manualBillingOffsetInitial;

#[ORM\Column]
public private(set) int $manualBillingOffsetReminder;

#[ORM\Column]
public private(set) int $manualBillingOffsetFinalDue;

#[ORM\Column]
public private(set) int $manualBillingOffsetOverdueFirst;

#[ORM\Column]
public private(set) int $manualBillingOffsetOverdueFinal;
```

Mark them `private(set)` only — once an order is placed, the schedule it was placed under is read-only forever. **No setter** (write-once via constructor). The cron reads them via `$contract->order->manualBillingOffsetInitial` etc. — there is no need to also copy them onto Contract since `Contract.order` is non-nullable and already eagerly available on the contract loads the cron does.

Update `Order::__construct(...)` to accept the five offsets as constructor parameters. They're always required (no nullable) — `OrderService::createOrder()` and `AdminCreateOnboardingHandler` derive them from `$place` at the call site.

**`src/Service/OrderService.php::createOrder()`** — after picking the storage but before instantiating Order, snapshot the place's schedule values onto the Order constructor args:

```php
$order = new Order(
    // … existing args …
    manualBillingOffsetInitial: $place->manualBillingOffsetInitial,
    manualBillingOffsetReminder: $place->manualBillingOffsetReminder,
    manualBillingOffsetFinalDue: $place->manualBillingOffsetFinalDue,
    manualBillingOffsetOverdueFirst: $place->manualBillingOffsetOverdueFirst,
    manualBillingOffsetOverdueFinal: $place->manualBillingOffsetOverdueFinal,
    expiresAt: $now->modify('+'.$place->orderExpirationDays.' days'),
    createdAt: $now,
);
```

`AdminCreateOnboardingHandler::__invoke()` reaches `OrderService::createOrder($user, $command->storageType, $command->place, …)` and inherits the snapshot for free. No additional change there.

**`src/Service/Billing/ManualBillingReminderSchedule.php`** becomes a **value object** built from an Order (not a service registered in DI):

```php
<?php
declare(strict_types=1);
namespace App\Service\Billing;

use App\Entity\Order;
use App\Entity\Place;

final readonly class ManualBillingReminderSchedule
{
    public const string STAGE_INITIAL = 'initial';
    public const string STAGE_REMINDER = 'd_minus_2';
    public const string STAGE_FINAL_DUE = 'd_zero';
    public const string STAGE_OVERDUE_FIRST = 'd_plus_3';
    public const string STAGE_OVERDUE_FINAL = 'd_plus_7';

    public function __construct(
        public int $offsetInitial,
        public int $offsetReminder,
        public int $offsetFinalDue,
        public int $offsetOverdueFirst,
        public int $offsetOverdueFinal,
    ) {}

    public static function fromOrder(Order $order): self
    {
        return new self(
            $order->manualBillingOffsetInitial,
            $order->manualBillingOffsetReminder,
            $order->manualBillingOffsetFinalDue,
            $order->manualBillingOffsetOverdueFirst,
            $order->manualBillingOffsetOverdueFinal,
        );
    }

    public static function fromPlace(Place $place): self
    {
        return new self(
            $place->manualBillingOffsetInitial,
            $place->manualBillingOffsetReminder,
            $place->manualBillingOffsetFinalDue,
            $place->manualBillingOffsetOverdueFirst,
            $place->manualBillingOffsetOverdueFinal,
        );
    }

    /** @return array<string, int> */
    public function stages(): array
    {
        return [
            self::STAGE_INITIAL => $this->offsetInitial,
            self::STAGE_REMINDER => $this->offsetReminder,
            self::STAGE_FINAL_DUE => $this->offsetFinalDue,
            self::STAGE_OVERDUE_FIRST => $this->offsetOverdueFirst,
            self::STAGE_OVERDUE_FINAL => $this->offsetOverdueFinal,
        ];
    }

    /**
     * Return the stage whose offset matches today's calendar-day diff from
     * $nextBillingDate. Compare in Europe/Prague calendar days (date('Y-m-d')),
     * not 24-hour intervals, so a DST shift or a midnight cron run hits the
     * intended day.
     *
     * Returns null when no stage falls on today.
     */
    public function dueStageOn(\DateTimeImmutable $now, \DateTimeImmutable $nextBillingDate): ?string
    {
        $today = $now->setTime(0, 0, 0);
        $anchor = $nextBillingDate->setTime(0, 0, 0);
        $diffDays = (int) $today->diff($anchor)->format('%r%a'); // signed: negative when today < anchor

        // dueStage iff (anchor + offset) == today  ⇔  offset == today - anchor == -diffDays
        $offsetToday = -$diffDays;

        foreach ($this->stages() as $stage => $offset) {
            if ($offset === $offsetToday) {
                return $stage;
            }
        }

        return null;
    }

    /** @return array{int, int} [minOffset, maxOffset] — used by SQL pre-filter on the cron */
    public function offsetBounds(): array
    {
        $offsets = array_values($this->stages());

        return [min($offsets), max($offsets)];
    }
}
```

No DI wiring — every caller constructs it via `fromOrder()` / `fromPlace()`.

**Place form** — `src/Form/PlaceFormData.php` gains the five `int` fields (defaulting to -7 / -2 / 0 / 3 / 7) with validation:

```php
#[Assert\Range(min: -90, max: 0, notInRangeMessage: 'Připomenutí před splatností musí být 0 až -90 dní.')]
public int $manualBillingOffsetInitial = -7;

#[Assert\Range(min: -90, max: 0)]
public int $manualBillingOffsetReminder = -2;

#[Assert\Range(min: -90, max: 0)]
public int $manualBillingOffsetFinalDue = 0;

#[Assert\Range(min: 1, max: 90, notInRangeMessage: 'Upomínka po splatnosti musí být 1 až 90 dní.')]
public int $manualBillingOffsetOverdueFirst = 3;

#[Assert\Range(min: 1, max: 90)]
public int $manualBillingOffsetOverdueFinal = 7;

#[Assert\Callback]
public function validateManualBillingOrdering(ExecutionContextInterface $context): void
{
    // Upcoming offsets must be strictly ascending (-7 < -2 < 0).
    if (!($this->manualBillingOffsetInitial < $this->manualBillingOffsetReminder
        && $this->manualBillingOffsetReminder < $this->manualBillingOffsetFinalDue)) {
        $context->buildViolation('Připomenutí před splatností musí být v pořadí: nejdříve nejvzdálenější, pak bližší (např. -7, -2, 0).')
            ->atPath('manualBillingOffsetInitial')
            ->addViolation();
    }

    // Overdue offsets must be strictly ascending (3 < 7).
    if (!($this->manualBillingOffsetOverdueFirst < $this->manualBillingOffsetOverdueFinal)) {
        $context->buildViolation('Upomínky po splatnosti musí být v pořadí (např. 3 < 7).')
            ->atPath('manualBillingOffsetOverdueFinal')
            ->addViolation();
    }
}
```

`src/Form/PlaceFormType.php` — admin-only panel "Časový plán manuálních plateb (dny vůči datu splatnosti)" gated by the existing `is_admin` form option (same gate that hides the instructions upload — landlords don't see this field). Five `IntegerType` fields with helpful labels:

```php
if ($options['is_admin']) {
    $builder
        ->add('manualBillingOffsetInitial', IntegerType::class, [
            'label' => 'Úvodní výzva k platbě (záporné číslo = X dní před splatností)',
            'help' => 'Výchozí: -7',
        ])
        ->add('manualBillingOffsetReminder', IntegerType::class, [
            'label' => 'Připomenutí',
            'help' => 'Výchozí: -2',
        ])
        ->add('manualBillingOffsetFinalDue', IntegerType::class, [
            'label' => 'V den splatnosti',
            'help' => 'Výchozí: 0',
        ])
        ->add('manualBillingOffsetOverdueFirst', IntegerType::class, [
            'label' => 'První upomínka po splatnosti (kladné číslo)',
            'help' => 'Výchozí: 3',
        ])
        ->add('manualBillingOffsetOverdueFinal', IntegerType::class, [
            'label' => 'Poslední upomínka po splatnosti',
            'help' => 'Výchozí: 7',
        ]);
}
```

The matching `CreatePlace` / `UpdatePlace` commands and handlers gain the five fields and propagate them to `Place::__construct(...)` / `Place::updateManualBillingSchedule(...)`.

**Migration** — auto-generated via `bin/console make:migration`. Backfill all existing Place rows + Order rows with the defaults (-7, -2, 0, 3, 7); Doctrine's `options: ['default' => …]` covers the schema-level default for future inserts but the migration must also include a one-time `UPDATE place SET …` for legacy rows (Doctrine doesn't backfill on ALTER ADD COLUMN unless you specify it explicitly in the migration).

**Tests** (added to Requirement 21):

- `tests/Unit/Entity/PlaceManualBillingScheduleTest.php` — defaults, `updateManualBillingSchedule()` mutates `updatedAt`.
- `tests/Unit/Form/PlaceFormDataManualBillingValidationTest.php` — ordering rule and range checks.
- `tests/Unit/Service/Billing/ManualBillingReminderScheduleTest.php` — `fromOrder` / `fromPlace` constructors, every branch of `dueStageOn()` including DST boundary, `offsetBounds()`.
- `tests/Integration/Service/OrderServiceManualBillingSnapshotTest.php` — creating an Order copies the Place's offsets onto the Order; subsequently mutating the Place does NOT change the existing Order.
- `tests/Integration/Controller/Portal/PlaceEditManualBillingTest.php` — admin sees the five inputs, landlord does not; saving persists.

### 7. New cron `app:send-manual-billing-payment-requests` (with explicit idempotency)

**Critical invariant: each (contract, billing cycle, stage) triple produces at most one outbound notification.** Re-runs of the cron — same day, same hour, in parallel, after a crash mid-loop, after manual replay — must never resend a stage that was already sent. The user asked specifically to "track what and when/which was sent so we do not send the same notice twice." Idempotency is enforced at three layers:

| Layer | Mechanism | Protects against |
|---|---|---|
| Schema | `UNIQUE(contract_id, period_start)` on `manual_payment_request` | Two cycles existing for the same period |
| Row state | `ManualPaymentRequest.sentStages` JSON map: `{ "initial": "2025-06-08T08:00:13+02:00", "d_minus_2": "...", "d_zero": "...", "d_plus_3": "...", "d_plus_7": "..." }` | Same stage emitting twice for the same cycle |
| Concurrency | `SELECT … FOR UPDATE` on the ManualPaymentRequest row inside the per-stage command handler | Two cron processes racing on the same row |

The cron itself is just the outer scheduler — the actual idempotent work happens inside a per-stage messenger command, so the `doctrine_transaction` middleware wraps the gate-check + state-write + event-dispatch into one transaction. If anything throws before commit, nothing visibly happened (next run picks up where this one left off). If the transaction commits but a downstream event handler (e.g. an SMTP send) throws, the stage is recorded as sent — the customer misses **at most one** reminder for that cycle but never receives duplicates.

**`src/Console/SendManualBillingPaymentRequestsCommand.php`** — daily cron. Outer loop:

1. **DB pre-filter**: `ContractRepository::findManualBillingCandidates(\DateTimeImmutable $now): Contract[]`
   - `c.billingMode = 'manual_recurring'`
   - `c.terminatedAt IS NULL`
   - `c.nextBillingDate IS NOT NULL`
   - `(c.endDate IS NULL OR c.endDate >= :now)`
   - `(c.terminatesAt IS NULL OR c.terminatesAt >= :now)`
   - `c.nextBillingDate BETWEEN :now - 90 DAY AND :now + 90 DAY` — a generous outer bound that contains every reasonable per-place offset (range validation on the form caps each offset at ±90 days). Per-place schedules vary, so don't try to compute the exact window in SQL — filter the wide window here, refine in PHP.
2. **Per-contract**: build `ManualBillingReminderSchedule::fromOrder($contract->order)`, then `$stage = $schedule->dueStageOn($now, $contract->nextBillingDate)`.
   - If `$stage === null` → today is not a notification day for this contract → skip.
3. **Dispatch the per-stage command** via the command bus:
   ```php
   $this->commandBus->dispatch(new DispatchManualBillingNotificationCommand(
       contractId: $contract->id,
       periodStart: $contract->nextBillingDate,
       stage: $stage,
   ));
   ```
4. Wrap per-contract work in `try { … } catch (\Throwable $e) { … }` mirroring `ProcessRecurringPaymentsCommand::recordFailure()` — log + continue. `EntityManager` reset on follow-up failure (closed-EM defence).

**`src/Command/DispatchManualBillingNotificationCommand.php`** — the readonly DTO:

```php
final readonly class DispatchManualBillingNotificationCommand
{
    public function __construct(
        public Uuid $contractId,
        public \DateTimeImmutable $periodStart,
        public string $stage,  // ManualBillingReminderSchedule::STAGE_*
    ) {}
}
```

**`src/Command/DispatchManualBillingNotificationHandler.php`** — the idempotent core:

```php
#[AsMessageHandler]
final readonly class DispatchManualBillingNotificationHandler
{
    public function __construct(
        private ContractRepository $contractRepository,
        private ManualPaymentRequestRepository $manualPaymentRequestRepository,
        private GoPayClient $goPayClient,
        private RecurringAmountCalculator $amountCalculator,
        private OrderStatusUrlGenerator $statusUrlGenerator,
        private UrlGeneratorInterface $urlGenerator,
        private MessageBusInterface $eventBus,
        private AuditLogger $auditLogger,
        private ClockInterface $clock,
        private Identity\ProvideIdentity $identityProvider,
    ) {}

    public function __invoke(DispatchManualBillingNotificationCommand $command): void
    {
        $now = $this->clock->now();
        $contract = $this->contractRepository->get($command->contractId);

        // 1. Atomically acquire-or-create the cycle row, locked FOR UPDATE.
        //    The unique (contract, period_start) constraint backstops the application-level race.
        $request = $this->manualPaymentRequestRepository->lockForUpdate($contract, $command->periodStart);

        if (null === $request) {
            $amount = $this->amountCalculator->calculate($contract, $now);
            $request = new ManualPaymentRequest(
                id: $this->identityProvider->next(),
                contract: $contract,
                periodStart: $command->periodStart,
                periodEnd: $this->computePeriodEnd($contract, $command->periodStart),
                amount: $amount,
                createdAt: $now,
            );
            $this->manualPaymentRequestRepository->save($request);
        }

        // 2. Idempotency gate — record-already-sent for this stage? bail.
        if ($request->hasStageSent($command->stage)) {
            return;
        }

        // 3. Already-paid? Nothing to remind about.
        if ('paid' === $request->status) {
            return;
        }

        // 4. Acquire / refresh the GoPay payment. Re-use unless GoPay reports terminal state.
        if (null === $request->goPayPaymentId || $this->isGoPayPaymentTerminal($request->goPayPaymentId)) {
            $payment = $this->goPayClient->createOneTimeCharge(
                amount: $request->amount,
                orderNumber: sprintf('MNL-%s-%s', $contract->id->toRfc4122(), $request->periodStart->format('Ymd')),
                orderDescription: sprintf('Pronájem skladu %s - %s (%s)',
                    $contract->storage->number,
                    $contract->storage->storageType->name,
                    $request->periodStart->format('m/Y'),
                ),
                payerEmail: $contract->user->email,
                returnUrl: $this->statusUrlGenerator->generate($contract->order),
                notificationUrl: $this->urlGenerator->generate('public_gopay_notification', [], UrlGeneratorInterface::ABSOLUTE_URL),
            );
            $request->attachGoPayPayment($payment->id, $payment->gwUrl);
        }

        // 5. Record stage as sent BEFORE the event dispatch — the doctrine_transaction
        //    middleware commits both together. If we dispatched first and then crashed,
        //    a retry would re-send the email; we deliberately prefer "miss one reminder"
        //    over "double-send".
        $request->recordStageSent($command->stage, $now);

        // 6. Audit row (the audit-log writer commits out-of-band per CLAUDE.md exception,
        //    so it survives even if the transaction rolls back — desirable for forensics).
        $this->auditLogger->logManualPaymentRequested($request, $command->stage);

        // 7. For overdue stages, also increment failedBillingAttempts so the overdue
        //    severity classifier (OverdueChecker) escalates with each nudge.
        if (in_array($command->stage, [
            ManualBillingReminderSchedule::STAGE_OVERDUE_FIRST,
            ManualBillingReminderSchedule::STAGE_OVERDUE_FINAL,
        ], true)) {
            $contract->recordFailedBillingAttempt($now);
        }

        // 8. Fan out the email side-effect via the event bus. Handlers run AFTER this
        //    transaction commits (DispatchDomainEventsMiddleware semantics — see
        //    .claude/MESSENGER.md). A throwing handler does not roll back sentStages.
        $event = match (true) {
            in_array($command->stage, [
                ManualBillingReminderSchedule::STAGE_OVERDUE_FIRST,
                ManualBillingReminderSchedule::STAGE_OVERDUE_FINAL,
            ], true) => new ManualBillingPaymentOverdue(
                contractId: $contract->id,
                manualPaymentRequestId: $request->id,
                stage: $command->stage,
                occurredOn: $now,
            ),
            default => new ManualBillingPaymentRequested(
                contractId: $contract->id,
                manualPaymentRequestId: $request->id,
                stage: $command->stage,
                occurredOn: $now,
            ),
        };
        $this->eventBus->dispatch($event);
    }

    /* helpers: computePeriodEnd, isGoPayPaymentTerminal */
}
```

**`ManualPaymentRequestRepository::lockForUpdate(Contract $contract, \DateTimeImmutable $periodStart): ?ManualPaymentRequest`** — uses Doctrine's `LockMode::PESSIMISTIC_WRITE` (or `setLockMode(LockMode::PESSIMISTIC_WRITE)` on the QueryBuilder) to acquire a row-level lock for the duration of the transaction. Pattern already in use by `OrderRepository::findByGoPayPaymentIdForUpdate` (referenced in `ProcessPaymentNotificationHandler.php:72`). Returns null when the row doesn't exist yet — caller creates one and the unique constraint catches any racing creator.

**`ManualPaymentRequest::recordStageSent(string $stage, \DateTimeImmutable $now): void`** — writes `$this->sentStages[$stage] = $now->format(\DateTimeInterface::ATOM)`. Stage names validated against the `ManualBillingReminderSchedule::STAGE_*` constants (throw `\InvalidArgumentException` on unknown stage — defensive).

**`ManualPaymentRequest::hasStageSent(string $stage): bool`** — `array_key_exists($stage, $this->sentStages)`.

This split gives us:

- **Outer cron** = enumerate + dispatch one command per contract per day. Pure scheduling, no DB writes. Crashes mid-loop just mean some contracts didn't get their command this minute — next cron run a few hours later or tomorrow picks them up because their `nextBillingDate` and stages haven't changed.
- **Per-stage handler** = the atomic idempotent unit. `doctrine_transaction` middleware wraps the lock + check + write + audit + event-dispatch into one commit. Repeated dispatch of the same command is a guaranteed no-op (stage gate at line "Idempotency gate").

The cron schedule entry goes alongside `app:process-recurring-payments` (look for it in the repo's cron/scheduler config — likely a `Symfony\Component\Scheduler` recipe or a deployment-side crontab).

### 8. New domain events + e-mail handlers

`src/Event/ManualBillingPaymentRequested.php`:

```php
final readonly class ManualBillingPaymentRequested
{
    public function __construct(
        public Uuid $contractId,
        public Uuid $manualPaymentRequestId,
        public string $stage,            // ManualBillingReminderSchedule::STAGE_*
        public \DateTimeImmutable $occurredOn,
    ) {}
}
```

`src/Event/ManualBillingPaymentOverdue.php` — same shape.

`src/Event/SendManualBillingPaymentRequestedEmailHandler.php` — renders one of three Twig templates based on `$event->stage`:

- `templates/email/manual_billing_payment_initial.html.twig` — "Vaše platba bude splatná za 7 dní"
- `templates/email/manual_billing_payment_reminder.html.twig` — "Připomenutí: platba splatná za 2 dny"
- `templates/email/manual_billing_payment_due_today.html.twig` — "Platba je splatná dnes"

Each template renders: the gateway URL as the CTA, amount in CZK (vč. DPH), period (`od dd.mm.yyyy do dd.mm.yyyy`), storage identification (place + storage type + storage number), and the status-permalink link.

`src/Event/SendManualBillingPaymentOverdueEmailHandler.php` — two templates:

- `templates/email/manual_billing_payment_overdue_first.html.twig` — "Platba je 3 dny po splatnosti"
- `templates/email/manual_billing_payment_overdue_final.html.twig` — "Poslední upomínka: 7 dní po splatnosti"

`src/Event/SendManualBillingOverdueAdminEmailHandler.php` — admin variant for both overdue stages, sent to every `ROLE_ADMIN` (looking at existing `SendRecurringPaymentFailedAdminEmailHandler` for the recipient-fan-out pattern).

All e-mails: from `noreply@fajnesklady.cz`, mailer call wrapped in try/catch logging via `'exception' => $e` (CLAUDE.md rule). Czech with full diacritics. Identification block, recurring-payment-legal-max disclosure NOT included (irrelevant for MANUAL).

### 9. Widen MRR / "active recurring" predicates

For every method listed in "MRR / 'active recurring' predicates" above (`src/Repository/ContractRepository.php`), replace:

```diff
- ->andWhere('c.goPayParentPaymentId IS NOT NULL')
+ ->andWhere("c.billingMode IN ('auto_recurring', 'manual_recurring')")
```

(In the raw SQL of `loadContractStatsByPlaceIds` use the literal string array predicate; in QueryBuilder calls use the values.)

Add a `ContractRepository` constant or reusable QueryBuilder factory to keep the predicate centralised:

```php
public const array RECURRING_BILLING_MODES = ['auto_recurring', 'manual_recurring'];
```

`findWithActiveRecurringByLandlord` (used by the landlord portal) is the canonical caller; verify the test in `tests/Integration/...` still passes after the widening (add a MANUAL contract fixture so the new branch is covered).

### 10. Order create form — billingMode radio + Live Component update

`src/Form/OrderFormData.php` — add:

```php
public BillingMode $billingMode = BillingMode::AUTO_RECURRING;

#[Assert\Callback]
public function validateBillingMode(ExecutionContextInterface $context): void
{
    // Unlimited MUST be AUTO (operational policy)
    if (RentalType::UNLIMITED === $this->rentalType && BillingMode::AUTO_RECURRING !== $this->billingMode) {
        $context->buildViolation('Pro pronájem na dobu neurčitou je dostupná pouze automatická platba kartou.')
            ->atPath('billingMode')
            ->addViolation();
    }

    // Short LIMITED is ONE_TIME (no choice surfaced; if a forged payload sends MANUAL, refuse)
    if (RentalType::LIMITED === $this->rentalType
        && null !== $this->startDate && null !== $this->endDate
        && (int) $this->startDate->diff($this->endDate)->days < PriceCalculator::WEEKLY_THRESHOLD_DAYS
        && BillingMode::ONE_TIME !== $this->billingMode) {
        $context->buildViolation('Pro krátkodobé pronájmy se platí jednorázově.')
            ->atPath('billingMode')
            ->addViolation();
    }
}
```

Update `OrderFormType` (the Live Component variant in `src/Twig/Component/OrderForm.php` — spec 008) to add a radio group between rental-type and start-date:

```php
->add('billingMode', EnumType::class, [
    'class' => BillingMode::class,
    'expanded' => true,
    'choices' => [
        'Automatická platba kartou' => BillingMode::AUTO_RECURRING,
        'Ručně schvalovaná platba (výzva e-mailem)' => BillingMode::MANUAL_RECURRING,
    ],
    'label' => 'Způsob platby',
])
```

In the Live Component template `templates/components/OrderForm.html.twig`:

- Wrap the radio group in `{% if eligibleForBillingModeChoice %}…{% endif %}`. `eligibleForBillingModeChoice` is true when LIMITED && computed days ≥ 28. Recomputed reactively as the customer changes dates.
- When MANUAL is selected, the schedule preview switches its column header from "Strhnutí z karty" → "Výzva k platbě e-mailem" and adds a small explanatory caption: "Před každou platbou Vám pošleme e-mail s odkazem k zaplacení."

Persist the field into the session via `OrderFormData::toSessionArray()` / `fromSessionArray()` — both methods need a new key `'billingMode'` → `BillingMode::value`.

### 11. Order accept page — hide recurring-consent for MANUAL

`templates/public/order_accept.html.twig`:

- Wrap the recurring-payment disclosure card (lines 385-460) **and** the dedicated consent checkbox (lines 461-470) **and** the hidden `accept_recurring_payments` input (line 470) in `{% if order.billingMode == constant('App\\Enum\\BillingMode::AUTO_RECURRING') %}…{% endif %}`.
- For MANUAL, immediately above the signature pad insert a small information card:

```twig
{% if order.billingMode == constant('App\\Enum\\BillingMode::MANUAL_RECURRING') %}
    <div class="…">
        <h3>Ručně schvalovaná platba</h3>
        <p>První platbu zaplatíte hned po podpisu. Před každou další platbou Vám 7 dní předem pošleme e-mail s odkazem k zaplacení. Žádná údaje o platební kartě se neukládají.</p>
    </div>
{% endif %}
```

- The Alpine `acceptRecurring` variable initialisation at line 32 (`acceptRecurring defaults to true when there's no recurring payment…`) keeps its behaviour — for MANUAL the variable stays true (no checkbox required to toggle it), the submit button stays enabled.
- Submit button text: unchanged (compliance).

The corresponding controller (`src/Controller/Public/OrderAcceptController.php`) — when persisting `acceptRecurring`, treat MANUAL the same as "no recurring required": no `acceptRecurring` flag in the audit log, no `accept_recurring_payments` field expected on the form.

### 12. Initiate-payment branch on billingMode (not on duration)

In `src/Command/InitiatePaymentHandler.php`, replace the `needsRecurringBilling()` check (lines 34-48) with an explicit dispatch on `Order.billingMode`:

```php
$payment = match ($order->billingMode) {
    BillingMode::ONE_TIME, BillingMode::MANUAL_RECURRING
        => $this->goPayClient->createPayment($order, $command->returnUrl, $command->notificationUrl),
    BillingMode::AUTO_RECURRING
        => $this->goPayClient->createRecurringPayment($order, $command->returnUrl, $command->notificationUrl),
};
```

MANUAL takes the same code path as ONE_TIME — a one-shot GoPay payment with no recurrence flag. The customer is paying the first month exactly as today; the difference is what we record afterwards.

### 13. Webhook fork on billingMode in `ProcessPaymentNotificationHandler`

`src/Command/ProcessPaymentNotificationHandler.php` — replace the existing `$needsRecurring = $this->priceCalculator->needsRecurringBilling(...)` (line 79) with:

```php
if ($order->billingMode === BillingMode::AUTO_RECURRING) {
    $order->setGoPayParentPaymentId($status->id);
    $this->eventBus->dispatch(new RecurringPaymentEstablished(/* … */));
}
```

So MANUAL orders skip the parent-payment-ID capture and skip the `RecurringPaymentEstablished` event (no Podmínky-IV confirmation e-mail — that confirmation is specific to the token-based track).

Inject `ManualPaymentRequestRepository`. After the existing "Not an order payment" branch (line 106), add:

```php
$manualRequest = $this->manualPaymentRequestRepository->findByGoPayPaymentId($command->goPayPaymentId);
if (null !== $manualRequest && $status->isPaid()) {
    $this->reconcileManualPayment($manualRequest, $status, $now);
    return;
}
```

`reconcileManualPayment()` mirrors `reconcileRecurringPayment()` (line 127) for the AUTO branch:

- Compute next billing dates the same way as `ChargeRecurringPaymentHandler::finalizeCharge`.
- `$contract->recordBillingCharge($now, $nextBillingDate, $paidThroughDate)` — same method AUTO uses, advances `lastBilledAt` / `nextBillingDate` / `paidThroughDate` and clears any failure counters.
- `$manualRequest->markPaid($now)`.
- Dispatch `RecurringPaymentCharged` event (REUSED — already triggers `IssueInvoiceOnRecurringChargeHandler` + `RecordPaymentOnRecurringChargeHandler`, giving us invoice + Payment row for free).
- Catch `HandlerFailedException` → `isUniqueViolation()` (existing helper) → log+return (mirrors the AUTO race-condition guard).

For amount mismatch: same `PaymentAmountMismatch` event using `RecurringAmountCalculator::calculate()` as the expected baseline.

### 14. Sync billingMode from order renew controller

`src/Controller/Public/OrderRenewController.php` — when constructing the new order from `previousOrder`, copy `previousOrder.billingMode` onto the prefilled OrderFormData (or directly onto the new Order if it's created without re-rendering the form). The customer can still change it on the form before signing.

### 15. Onboarding form — billingMode radio (admin)

`src/Form/AdminCreateOnboardingFormData.php` — add:

```php
public BillingMode $billingMode = BillingMode::AUTO_RECURRING;

#[Assert\Callback]
public function validateBillingMode(ExecutionContextInterface $context): void
{
    if (RentalType::UNLIMITED === $this->rentalType && BillingMode::AUTO_RECURRING !== $this->billingMode) {
        $context->buildViolation('Pro pronájem na dobu neurčitou je dostupná pouze automatická platba kartou.')
            ->atPath('billingMode')->addViolation();
    }
}
```

`src/Form/AdminCreateOnboardingFormType.php` — add radio in the same panel as `paymentMethod` (with help text clarifying that `paymentMethod` decides the **first** payment, `billingMode` decides **subsequent** months):

```php
->add('billingMode', EnumType::class, [
    'class' => BillingMode::class,
    'label' => 'Způsob následných plateb',
    'expanded' => true,
    'choices' => [
        'Automatická (uloží se karta, strhává se sama)' => BillingMode::AUTO_RECURRING,
        'Ručně (každý měsíc dostane e-mail s platebním odkazem)' => BillingMode::MANUAL_RECURRING,
    ],
    'help' => 'Pro pronájem na dobu neurčitou je dostupná pouze automatická.',
])
```

`AdminCreateOnboardingCommand` (and `AdminCreateOnboardingHandler`) carry the new field; the handler calls `$order->setBillingMode($command->billingMode)` after creating the order.

For `AdminMigrateCustomer` (lump-sum prepayment for an existing customer being onboarded): add the same field — it controls what happens after the prepaid period runs out.

The Czech UI text MUST use full diacritics (memory note).

### 16. Skip MANUAL contracts in the external-prepayment-ending-soon cron

`src/Repository/ContractRepository.php::findExternalPrepaymentsEndingInRange()` (line 676): add `->andWhere("c.billingMode != 'manual_recurring'")`. For MANUAL contracts that are externally prepaid, the new MANUAL cron sends the d-7 payment-request e-mail at the right moment — sending the "set up auto" e-mail in parallel would confuse the customer.

### 17. View-model updates — `OrderStatusViewModelFactory`

`src/Service/Order/OrderStatusViewModelFactory.php`:

- The `cancelRecurringUrl` (line 64) currently shows when `$contract->hasActiveRecurringPayment()`. MANUAL contracts have no token → `hasActiveRecurringPayment()` returns false → no button shown. No change here, but verify behaviour.
- Add a new property `payManualNowUrl` on `OrderStatusViewModel`. Populate by calling `ManualPaymentRequestRepository::findPendingForCurrentCycle($contract, $now)` — when present and the request has a `goPayGatewayUrl`, set `payManualNowUrl = $request->goPayGatewayUrl`. Otherwise null.
- Add a new property `nextManualPaymentRequestDate` (the next d-7 anchor — `$contract->nextBillingDate->modify(sprintf('%d days', $schedule->upcomingOffsets[0]))`).
- `templates/public/order_status.html.twig` + `templates/portal/user/order/detail.html.twig`: render "Zaplatit nyní" CTA when `payManualNowUrl` is set, plus "Příští výzva k platbě: dd.mm.yyyy" hint.

### 18. Customer billing-status partial — two new MANUAL variants

`templates/components/customer_billing_status.html.twig` (spec 030) — add at the top of the priority chain (before the existing free / external-prepaid variants):

- **MANUAL_RECURRING with pending unpaid request in this cycle** → amber card: "Platba k zaplacení: {amount} Kč vč. DPH — splatná {periodStart|date('d.m.Y')}. [Zaplatit přes GoPay](payManualNowUrl)"
- **MANUAL_RECURRING, no pending request right now** → neutral info card: "Platby probíhají ručně. Před každou platbou (7 dní předem) Vám pošleme e-mail s odkazem k zaplacení. Příští: {nextManualPaymentRequestDate|date('d.m.Y')}."

The factory needs to be widened to expose the manual-billing state — pass `ManualPaymentRequestRepository` in and resolve the same way the existing externally-prepaid state is resolved (return early on the first matching variant).

### 19. Overdue reason labels for MANUAL

`src/Service/Overdue/OverdueChecker.php::buildView()` (lines 88-127) — for MANUAL contracts, swap the reason label:

- `nextBillingDate < now-1day` AND `billingMode == MANUAL_RECURRING` AND no failed attempts → reasonLabel = `'Zákazník nezaplatil výzvu'` (instead of `'Strhnutí splatné'`).
- Severity stays WARNING on first day, ERROR after the FINAL_OVERDUE reminder has been sent (read `$contract->failedBillingAttempts` — for MANUAL we increment this once at d+3 and once at d+7, so it's a faithful "we've nudged N times" counter; see Requirement 7 step 8 below, the overdue events should also call `$contract->recordFailedBillingAttempt($now)`).

Wait — careful: `recordFailedBillingAttempt()` resets `pendingRecurringPaymentId` and is called from the AUTO retry path. For MANUAL we want the side-effect of incrementing `failedBillingAttempts` and setting `lastBillingFailedAt` but NOT to confuse the AUTO retry cron. The AUTO retry cron filters on `goPayParentPaymentId IS NOT NULL` (`ContractRepository::findNeedingRetry`, line 391), so MANUAL contracts (no token) are naturally excluded — safe to reuse the method.

### 20. Audit logging

Add to `src/Service/AuditLogger.php` (and `AuditLogDescriptionRenderer`):

- `logManualPaymentRequested(ManualPaymentRequest, string $stage)`
- `logManualPaymentReceived(ManualPaymentRequest)`
- `logBillingModeSetOnOrder(Order)` — fires from order creation (both customer and admin flows)

Render Czech descriptions for the timeline view of `/objednavka/{id}/stav`.

### 21. Tests

Place each new test file next to its production counterpart (mirror the existing folder layout under `tests/Unit/` and `tests/Integration/`).

**Unit** (in `tests/Unit/`):

1. `Enum/BillingModeTest.php` — labels, `isRecurring()`.
2. `Service/Billing/ManualBillingReminderScheduleTest.php` — every stage's `dueStageOn` branch, including edge cases (today is d-7 exactly, today is between two stages).
3. `Entity/ContractBillingModeTest.php` — `applyBillingMode`, default value.
4. `Entity/OrderBillingModeTest.php` — pins `Order::isRecurring()` against `BillingMode::isRecurring()` shape.
5. `Form/OrderFormDataBillingModeValidationTest.php` — every branch of `validateBillingMode`.
6. `Form/AdminCreateOnboardingBillingModeValidationTest.php` — UNLIMITED + MANUAL rejected.
7. `Service/GoPay/GoPayApiClientCreateOneTimeChargeTest.php` — verifies the new primitive builds the right payload (no recurrence keys).

**Integration** (in `tests/Integration/`):

8. `Console/SendManualBillingPaymentRequestsCommandTest.php`:
   - MockClock-driven. Set up a MANUAL contract with `nextBillingDate = 2025-06-22` (so 2025-06-15 is d-7) and Place with default offsets [-7, -2, 0, +3, +7].
   - Assert one `ManualPaymentRequest` row, one event dispatched (`ManualBillingPaymentRequested` with stage=INITIAL), `EmailLog` entry present, audit log present, `sentStages['initial']` populated.
   - Advance to d-2 → second stage. Advance to d-0 → third stage. After all three, `sentStages` has three keys with three distinct timestamps.
   - Advance to d+3 → overdue first; `failedBillingAttempts` incremented to 1. d+7 → overdue final; `failedBillingAttempts` = 2.
   - **Idempotency #1 — re-run same day**: run the cron a second time at d-7 without advancing the clock. Assert: same `ManualPaymentRequest` row (no duplicate via unique constraint), `sentStages` size unchanged, no additional event dispatched, no additional `EmailLog` row.
   - **Idempotency #2 — re-run after time advance, same stage**: at d-7 send, advance clock by 1 hour (still d-7), re-run. Same assertion — already-sent stage is skipped.
   - **Idempotency #3 — between-stages re-run**: at d-7 send, advance by 3 days (= d-4, no stage scheduled), re-run. Cron computes `dueStageOn` = null → no command dispatched, `sentStages` unchanged.
   - **Idempotency #4 — payment paid between stages**: at d-7 send, simulate webhook setting `ManualPaymentRequest.status = 'paid'`. Advance to d-2. Re-run cron — the per-stage handler sees `status === 'paid'` and skips. No d-2 email sent. `sentStages` does not gain `d_minus_2`.
   - **Per-place custom schedule**: second contract on a different Place with offsets `[-14, -5, 0, +1, +14]`. Set `nextBillingDate` accordingly; assert each stage fires on the customised day, not the default day.
   - **Schedule snapshot persistence**: update the second Place's offsets mid-test (e.g., flip -14 to -3). The existing contract's order keeps the original `manualBillingOffset*` values — the cron continues firing on -14 because the snapshot was locked in.
9. `Command/DispatchManualBillingNotificationHandlerTest.php` — direct handler tests (faster than the full console integration):
   - Lock contention: open a transaction in test A holding the row, dispatch in test B — assert B blocks until A commits, then sees the already-sent stage and no-ops. (Use the existing DAMA DoctrineTestBundle test isolation; this is the same pattern the `OrderRepository::findByGoPayPaymentIdForUpdate` tests rely on.)
   - Unknown stage string → `\InvalidArgumentException` from `recordStageSent`.
   - `GoPayClient` returns CANCELED for the previously-attached payment → handler creates a new GoPay payment and updates the request.
10. `Command/ProcessPaymentNotificationHandlerManualBillingTest.php` — webhook arrival for a manual payment ID reconciles the contract (records charge, advances paidThroughDate, dispatches `RecurringPaymentCharged`, issues invoice, persists Payment row).
11. `Controller/Public/OrderCreateBillingModeTest.php` — customer picks MANUAL, completes the order; resulting `Order.billingMode = MANUAL_RECURRING`, `Contract.billingMode = MANUAL_RECURRING`, no `goPayParentPaymentId` on either, no `RecurringPaymentEstablished` event, no recurring-payment-established e-mail in the EmailLog.
12. `Controller/Public/OrderAcceptManualBillingTest.php` — order_accept page for a MANUAL order does NOT render the dedicated recurring-payment consent checkbox; submitting without `accept_recurring_payments` succeeds.
13. `Controller/Portal/Admin/AdminCreateOnboardingManualBillingTest.php` — admin creates an onboarding with billingMode=MANUAL; signing email sent; after customer completes signing + first payment, Contract.billingMode=MANUAL.
14. `Repository/ContractRepositoryRecurringPredicateTest.php` — MRR + active-recurring queries now include MANUAL contracts but still exclude externally-prepaid-not-converted.

All tests run with MockClock at `2025-06-15 12:00:00 UTC`; build dates relative to that anchor.

## Acceptance

- [ ] Customer ordering a fixed-term LIMITED rental of ≥ 28 days sees a "Způsob platby" radio (Automatická / Ručně schvalovaná) on `/objednavka/{place}/{type}/{?storage}`. Schedule preview labels match the selection.
- [ ] Customer ordering UNLIMITED or short LIMITED does NOT see the radio.
- [ ] Selecting MANUAL → `/prijmout` page does NOT show the recurring-consent disclosure card or the "Souhlasím s opakovanou platbou" checkbox; submit button still reads `OBJEDNÁVÁM a zaplatím`.
- [ ] First payment for MANUAL: standard GoPay one-time payment, NO `recurrence_cycle` in the GoPay payload, NO `goPayParentPaymentId` saved after payment, NO `RecurringPaymentEstablished` event.
- [ ] `app:send-manual-billing-payment-requests` cron, run at d-7 / d-2 / d-0 (relative to the Order's snapshotted offsets, not the live Place's), produces three customer e-mails for that cycle, each containing the same gw_url; the `ManualPaymentRequest` row exists once per `(contract, periodStart)` (unique constraint enforced).
- [ ] **Idempotency**: running the cron twice on the same day, an hour apart, or in parallel, produces NO duplicate `EmailLog` row and NO duplicate event for any already-sent stage. `ManualPaymentRequest.sentStages` records the timestamp of each successful dispatch keyed by stage name (`initial` / `d_minus_2` / `d_zero` / `d_plus_3` / `d_plus_7`).
- [ ] **Schedule lock-in**: editing a Place's `manualBillingOffset*` values does NOT change the schedule of any Order created before the edit. New orders inherit the updated values; existing orders keep their snapshot.
- [ ] Customer pays the link → webhook arrives → `Contract.lastBilledAt`, `Contract.nextBillingDate`, `Contract.paidThroughDate` all advance one month (or to `endDate` if last cycle); a Fakturoid invoice is issued; a Payment row exists with the GoPay payment ID.
- [ ] Customer does NOT pay → cron sends d+3 and d+7 overdue e-mails to the customer plus admin-broadcast e-mails; contract appears in `/portal/admin/po-splatnosti` with reason `'Zákazník nezaplatil výzvu'`; daily admin digest (spec 031) picks it up.
- [ ] Admin onboarding form has a new "Způsob následných plateb" radio with the same eligibility rules; selecting MANUAL with rentalType=UNLIMITED fails validation in Czech.
- [ ] Order renew (`/objednavka/prodlouzit/{previousOrderId}`) prefills the same `billingMode` as the previous order; customer can change it.
- [ ] Landlord MRR / "active recurring count" / per-place expected revenue all include MANUAL contracts.
- [ ] `Contract::hasActiveRecurringPayment()` semantics unchanged → "Zrušit opakovanou platbu" button does NOT appear for MANUAL on `/stav` or `/portal/objednavky/{id}` (the contract is terminated via the existing termination path).
- [ ] `templates/components/customer_billing_status.html.twig` shows the new MANUAL variants on `/stav` and portal order detail. Czech text with full diacritics.
- [ ] `docker compose exec web composer quality` is green; full `composer test` is green (1100+ tests).
- [ ] One migration file generated via `bin/console make:migration`; `bin/console doctrine:schema:validate` clean; backfill DML for existing contracts/orders verified by re-running the integration suite.

## Out of scope

- **Admin mode switch on existing contracts.** Decided: locked at creation. AUTO↔MANUAL swap requires terminate + re-onboard. Saves one form, one event, one customer-facing public-token-establish flow.
- **Customer mode switch via portal.** Same rationale.
- **One-time-link per individual reminder.** Decided: one GoPay payment per cycle, reused across the three reminder e-mails; if GoPay returns terminal on getStatus, the next reminder creates a fresh payment. Avoids race conditions where the customer pays an "old" link.
- **MANUAL for short LIMITED (< 28 days).** They're already one-shots — no recurring cycle exists.
- **Custom per-place reminder cadences.** `ManualBillingReminderSchedule` is wired as a single service; per-place override is future work if needed.
- **VOP rewording.** The dynamic-VOP pipeline (spec 035) is unchanged. Standard terms apply to both billing tracks — the difference is operational. Operator can mention manual-billing on their VOP DOCX template if they choose; we add no placeholder for it.
- **Customer-facing nag past d+7.** From d+8 onward, admin handles offline. The contract sits in the overdue queue with severity ERROR; admin can terminate or contact the customer.
- **`SendRentalActivatedEmailHandler` MANUAL-specific copy.** The post-payment e-mail is intentionally not branched for MANUAL — the new MANUAL variants on the billing-status partial (Requirement 18) carry the same information, and the customer will see them on `/stav` immediately. Adding a fork to the rental-activated template would mean two near-identical templates to maintain.
- **Refunds.** Refund / reversal flows are out of scope; if a customer pays and is later refunded, admin handles via Fakturoid as today.

## Open questions

None — proceed.
