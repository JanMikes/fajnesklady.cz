# 058 — Contract always fixed-term ("doba určitá") with automatic extension per VOP §IV

**Status:** done
**Type:** feature / compliance
**Scope:** large (~2 new files, ~25 modified, 1 migration)
**Depends on:** none

## Problem

VOP §IV explicitly states: "doba nájmu sjednána na dobu určitou s možností prodloužení." But the system currently treats UNLIMITED orders as "doba neurčitá" — `Contract.endDate` is null, the contract DOCX says "Nájem se sjednává na dobu neurčitou," and ~20 templates display "Na dobu neurčitou." This is a direct contradiction of the operative legal terms.

The auto-extension mechanism described in VOP §IV is not implemented at all: recurring contracts should auto-extend by one billing period on each successful payment, and stop extending when the customer revokes recurring consent or falls into arrears.

## Goal

Every contract — regardless of whether the customer chose "Neomezeně" or a fixed period — is always "doba určitá" (fixed-term). The contract period reflects what has been paid for:
- **UNLIMITED + monthly recurring**: contract "od X do X+1 měsíc", auto-extends monthly
- **UNLIMITED + yearly recurring**: contract "od X do X+1 rok", auto-extends yearly
- **LIMITED + ONE_TIME**: full customer-chosen period (no change)
- **LIMITED + recurring**: customer-chosen period (no change to initial endDate; auto-extension after expiry is a follow-up spec)

On each successful recurring payment, `Contract.endDate` advances by one billing period. If payment fails, the contract stays active during the retry window (grace period, documented as a configurable policy), then terminates at day 7 per existing billing failure flow. If the customer revokes recurring consent, the contract expires at its current `endDate` with no additional notice period.

## Context (current state)

### Contract entity — endDate null for UNLIMITED
- `src/Entity/Contract.php:134` — `endDate` is `DATE_IMMUTABLE, nullable`
- `src/Entity/Contract.php:176-187` — `isActive()` treats null endDate as "always active"
- `src/Entity/Contract.php:199-202` — `isUnlimited()` checks `RentalType::UNLIMITED`
- `src/Entity/Contract.php:368-375` — `isLongTermMonthly()` returns true when `endDate === null`, used for long-term pricing

### Contract document generator — "na dobu neurčitou" for UNLIMITED
- `src/Service/ContractDocumentGenerator.php:221-234` — `formatRentalDuration()` branches on `RentalType::UNLIMITED || endDate === null` → "Nájem se sjednává na dobu neurčitou"

### Order → Contract date flow
- `src/Service/OrderService.php:176-177` — `completeOrder()` copies `Order.endDate` (null for UNLIMITED) directly to Contract
- `src/Command/CompleteOrderHandler.php:24-77` — sets up billing dates post-creation; never touches endDate

### Contract billing — endDate never advances
- `src/Entity/Contract.php:216-225` — `recordBillingCharge()` advances `paidThroughDate` and `nextBillingDate` but does NOT touch `endDate`

### Termination — only UNLIMITED can be terminated by customer
- `src/Service/Security/ContractVoter.php:46-48` — `TERMINATE` gate: `isUnlimited() && !isTerminated()`
- `src/Controller/Portal/User/ContractTerminateController.php:51` — `isUnlimited()` check, rejects LIMITED

### Termination cron — terminates any contract past endDate
- `src/Repository/ContractRepository.php:467-480` — `findDueForTermination()` includes `c.endDate IS NOT NULL AND c.endDate <= :now` — would terminate auto-extending contracts if endDate isn't advanced in time

### Expiration reminders — skips UNLIMITED (endDate null)
- `src/Console/SendExpirationRemindersCommand.php:33` — REMINDER_DAYS = [30, 7, 1]
- `src/Service/ContractService.php:143-150` — filters by `endDate >= targetDate && endDate < nextDay` — null endDate contracts never match

### Occupancy/planning — null endDate = "forever"
- `src/Twig/Components/PlaceOccupancyMap.php:132` — `'isUnlimited' => null === $contract->endDate && null === $contract->terminatesAt`

### Repository queries — 12 instances of `c.endDate IS NULL OR c.endDate >= :now`
- `src/Repository/ContractRepository.php` lines 82, 117, 146, 176, 383, 728, 837, 1088, 1175

### Templates — ~20 files with "Na dobu neurčitou" or endDate-null branches
Key files: `admin/order/detail.html.twig`, `portal/user/order/detail.html.twig`, `portal/landlord/order/detail.html.twig`, `portal/dashboard_user.html.twig`, order lists, calendar, storage lists, occupancy, order form, order status, payment page, email templates.

### Form type labels
- `src/Form/OrderFormType.php:153` — `RentalType::UNLIMITED => 'Na dobu neurčitou'`
- `src/Form/AdminOnboardingFormType.php:100` — `RentalType::UNLIMITED => 'Doba neurčitá'`

### Renewal flow — blocks UNLIMITED
- `src/Controller/Public/OrderRenewController.php:66-70` — redirects UNLIMITED with flash "Vaše smlouva je na dobu neurčitou"

## Requirements

### 1. Compute endDate for UNLIMITED contracts at creation

**File:** `src/Service/OrderService.php`

In `completeOrder()`, when `Order.endDate` is null (UNLIMITED), compute it from `startDate + billingCadenceStep` instead of copying null:

```php
$paymentFrequency = $order->paymentFrequency ?? PaymentFrequency::MONTHLY;
$cadenceStep = PaymentFrequency::YEARLY === $paymentFrequency ? '+1 year' : '+1 month';
$endDate = $order->endDate ?? $order->startDate->modify($cadenceStep);

$contract = new Contract(
    // ...
    startDate: $order->startDate,
    endDate: $endDate,
    // ...
);
```

`Order.endDate` stays null for UNLIMITED orders — the Order represents customer intent; the Contract represents the legal reality.

### 2. Auto-extend endDate on successful billing charge

**File:** `src/Entity/Contract.php`

In `recordBillingCharge()`, advance `endDate` to match `paidThroughDate`:

```php
public function recordBillingCharge(\DateTimeImmutable $chargedAt, ?\DateTimeImmutable $nextBillingDate, \DateTimeImmutable $paidThroughDate): void
{
    $this->lastBilledAt = $chargedAt;
    $this->nextBillingDate = $nextBillingDate;
    $this->paidThroughDate = $paidThroughDate;
    $this->failedBillingAttempts = 0;
    $this->lastBillingFailedAt = null;
    $this->pendingRecurringPaymentId = null;
    $this->paymentDemandSentAt = null;

    // VOP §IV: successful charge extends the contract period
    if ($this->billingMode->isRecurring()) {
        $this->endDate = $paidThroughDate;
    }
}
```

### 3. isActive() grace period for recurring contracts past endDate

**File:** `src/Entity/Contract.php`

Update `isActive()` to keep recurring contracts active past endDate while billing is in progress:

```php
public function isActive(\DateTimeImmutable $now): bool
{
    if (null !== $this->terminatedAt) {
        return false;
    }

    if (null !== $this->endDate && $now > $this->endDate) {
        // VOP §IV grace: recurring contracts stay active past endDate
        // while the billing/retry cycle is in progress. They are only
        // deactivated by explicit termination (payment failure at day 7,
        // admin action, or natural expiry after recurring revocation).
        //
        // POLICY DECISION (2026-05-27): grace is ON. To switch to
        // strict endDate expiration, remove this block — the
        // ProcessContractTerminationsCommand will terminate expired
        // contracts on the next cron run.
        return $this->isInBillingGrace();
    }

    return true;
}
```

New private method:

```php
private function isInBillingGrace(): bool
{
    if (!$this->billingMode->isRecurring()) {
        return false;
    }

    return match ($this->billingMode) {
        BillingMode::AUTO_RECURRING => null !== $this->goPayParentPaymentId,
        BillingMode::MANUAL_RECURRING => null !== $this->nextBillingDate || $this->failedBillingAttempts > 0,
        default => false,
    };
}
```

Explanation:
- **AUTO_RECURRING**: grace while GoPay token is active (`goPayParentPaymentId !== null`). When customer revokes recurring via GoPay → token is cleared → grace ends → contract expires at endDate.
- **MANUAL_RECURRING**: grace while `nextBillingDate` is set (cron still expects to bill) or retries are pending (`failedBillingAttempts > 0`). When the billing cycle exhausts retries → contract is terminated explicitly.

### 4. Contract document text — always "na dobu určitou"

**File:** `src/Service/ContractDocumentGenerator.php`

Replace `formatRentalDuration()`:

```php
private function formatRentalDuration(RentalType $rentalType, \DateTimeImmutable $startDate, ?\DateTimeImmutable $endDate): string
{
    $start = $startDate->format('d.m.Y');

    if (null === $endDate) {
        // Defensive fallback — should not happen for new contracts
        return sprintf('Nájem se sjednává na dobu určitou, a to od %s', $start);
    }

    return sprintf(
        'Nájem se sjednává na dobu určitou, a to od %s do %s',
        $start,
        $endDate->format('d.m.Y'),
    );
}
```

The `$rentalType` parameter is no longer used for branching but kept in the signature for backward compatibility (called from `renderBytes()` which passes it).

### 5. Fix isLongTermMonthly() — UNLIMITED = always long-term

**File:** `src/Entity/Contract.php`

After this change, UNLIMITED contracts have endDate = startDate + 1 month. Without a fix, `isLongTermMonthly()` would return false (30 days < 180 days threshold), causing UNLIMITED customers to be charged short-term rates. Fix:

```php
private function isLongTermMonthly(): bool
{
    if (RentalType::UNLIMITED === $this->rentalType) {
        return true;
    }

    if (null === $this->endDate) {
        return true;
    }

    return (int) $this->startDate->diff($this->endDate)->days >= PriceCalculator::SHORT_TERM_THRESHOLD_DAYS;
}
```

### 6. Exclude auto-extending contracts from termination cron

**File:** `src/Repository/ContractRepository.php`

Update `findDueForTermination()` to exclude recurring contracts that are in billing grace:

```php
public function findDueForTermination(\DateTimeImmutable $now): array
{
    return $this->entityManager->createQueryBuilder()
        ->select('c')
        ->from(Contract::class, 'c')
        ->where('c.terminatedAt IS NULL')
        ->andWhere(
            // 1. Pending termination notice due
            '(c.terminatesAt IS NOT NULL AND c.terminatesAt <= :now) OR '
            // 2. ONE_TIME contracts past endDate
            .'(c.endDate IS NOT NULL AND c.endDate <= :now AND c.billingMode = :oneTime) OR '
            // 3. AUTO_RECURRING past endDate with NO active GoPay token (recurring revoked)
            .'(c.endDate IS NOT NULL AND c.endDate <= :now AND c.billingMode = :autoRecurring AND c.goPayParentPaymentId IS NULL) OR '
            // 4. MANUAL_RECURRING past endDate with no pending billing AND no retry in progress
            .'(c.endDate IS NOT NULL AND c.endDate <= :now AND c.billingMode = :manualRecurring AND c.nextBillingDate IS NULL AND c.failedBillingAttempts = 0)'
        )
        ->setParameter('now', $now)
        ->setParameter('oneTime', BillingMode::ONE_TIME->value)
        ->setParameter('autoRecurring', BillingMode::AUTO_RECURRING->value)
        ->setParameter('manualRecurring', BillingMode::MANUAL_RECURRING->value)
        ->getQuery()
        ->getResult();
}
```

### 7. Expiration reminders — skip contracts with active recurring

**File:** `src/Service/ContractService.php`

In `findContractsExpiringOnDay()`, filter out contracts that will auto-extend:

```php
public function findContractsExpiringOnDay(int $daysFromNow, \DateTimeImmutable $now): array
{
    // ... existing query ...

    return array_filter($contracts, function (Contract $contract) use ($targetDate, $nextDay) {
        if (null === $contract->endDate) {
            return false;
        }
        $endDate = $contract->endDate->setTime(0, 0, 0);
        if ($endDate < $targetDate || $endDate >= $nextDay) {
            return false;
        }

        // Skip contracts that will auto-extend (recurring with active billing)
        if ($contract->billingMode->isRecurring() && !$contract->hasPendingTermination()) {
            // AUTO: skip if GoPay token active
            if (BillingMode::AUTO_RECURRING === $contract->billingMode && null !== $contract->goPayParentPaymentId) {
                return false;
            }
            // MANUAL: skip if nextBillingDate set (billing cycle pending)
            if (BillingMode::MANUAL_RECURRING === $contract->billingMode && null !== $contract->nextBillingDate) {
                return false;
            }
        }

        return true;
    });
}
```

### 8. Customer termination — remove isUnlimited gate, add "recurring" gate

**File:** `src/Service/Security/ContractVoter.php` (line 46-48)

Replace `isUnlimited()` with `billingMode->isRecurring()`:

```php
if (self::TERMINATE === $attribute) {
    return $contract->billingMode->isRecurring() && !$contract->isTerminated() && !$contract->hasPendingTermination();
}
```

**File:** `src/Controller/Portal/User/ContractTerminateController.php` (line 51-55)

Replace the `isUnlimited()` check:

```php
if (!$contract->billingMode->isRecurring()) {
    $this->addFlash('error', 'Smlouvu s jednorázovou platbou nelze předčasně ukončit.');
    return $this->redirectToRoute('portal_user_order_detail', ['id' => $contract->order->id]);
}
```

The termination mechanism changes for UNLIMITED: instead of the notice-period flow, revoking recurring should make the contract expire at the current endDate. The controller should dispatch a command that:
1. Cancels the recurring payment (GoPay void for AUTO_RECURRING)
2. Sets `nextBillingDate = null` (stops future billing)
3. Does NOT set `terminatesAt` (the contract expires naturally at `endDate`)

This replaces the current `RequestTerminationNoticeCommand` dispatch for UNLIMITED contracts. Keep the existing notice-period mechanism available for admin terminations (spec 055).

New command: `CancelContractRecurringCommand` — wraps the GoPay void + nextBillingDate clear + records an audit log entry. The ProcessContractTerminationsCommand will pick up the expired contract on its next run (requirement 6, case 3/4).

```php
// src/Command/CancelContractRecurringCommand.php
final readonly class CancelContractRecurringCommand
{
    public function __construct(
        public Contract $contract,
    ) {}
}
```

```php
// src/Command/CancelContractRecurringHandler.php
#[AsMessageHandler]
final readonly class CancelContractRecurringHandler
{
    public function __construct(
        private GoPayClient $goPayClient,
        private AuditLogger $auditLogger,
    ) {}

    public function __invoke(CancelContractRecurringCommand $command): void
    {
        $contract = $command->contract;

        if ($contract->hasActiveRecurringPayment()) {
            $this->goPayClient->voidRecurrence($contract->goPayParentPaymentId);
        }

        $contract->cancelRecurringPayment();
        $this->auditLogger->logContractRecurringCancelled($contract);
    }
}
```

Update `ContractTerminateController` to dispatch `CancelContractRecurringCommand` instead of `RequestTerminationNoticeCommand`:

```php
$this->commandBus->dispatch(new CancelContractRecurringCommand($contract));

$this->addFlash('success', sprintf(
    'Opakované platby byly zrušeny. Smlouva skončí %s.',
    $contract->endDate?->format('d.m.Y') ?? '',
));
```

### 9. OrderRenewController — allow renewal of UNLIMITED

**File:** `src/Controller/Public/OrderRenewController.php` (line 66-70)

Remove the UNLIMITED block. UNLIMITED contracts with expired endDate can now be renewed (the customer needs to place a new order to get a new billing period):

```php
// Remove the entire if (RentalType::UNLIMITED) block that redirects away.
// UNLIMITED contracts now have endDate, so the renewal logic below
// (date calculation + prefill) works for both types.
```

Update the date calculation to handle UNLIMITED: for UNLIMITED renewals, set `rentalType = UNLIMITED` (not LIMITED) so the new order also creates an auto-extending contract.

### 10. Repository query updates — remove `c.endDate IS NULL` as "unlimited"

**File:** `src/Repository/ContractRepository.php`

All `c.endDate IS NULL OR c.endDate >= :now` clauses were written when UNLIMITED had null endDate. Now UNLIMITED has endDate set, so these clauses can be simplified.

However, for **backward compatibility with existing data** (old UNLIMITED contracts that may still have null endDate until the migration backfill runs), keep the `IS NULL` fallback. The backfill migration (requirement 14) resolves this — after it runs, the IS NULL arm is dead code that can be removed in a follow-up cleanup.

**No change needed in this spec** — the queries are already correct (they include null endDate as "active"). The backfill migration handles the transition.

### 11. Template updates — replace "Na dobu neurčitou" with dates

Every template that says "Na dobu neurčitou" when `order.endDate is null` or `contract.endDate is null` should instead show the actual dates with an annotation for recurring contracts.

**Pattern for order/contract detail pages:**

Replace:
```twig
{% if order.endDate %}
    Na dobu určitou
{% else %}
    Na dobu neurčitou
{% endif %}
```

With:
```twig
Na dobu určitou
{% if order.rentalType.value == 'unlimited' %}
    <span class="text-sm text-gray-500">(s automatickým prodlužováním)</span>
{% endif %}
```

And for date display, replace:
```twig
{% if order.endDate %}
    {{ order.startDate|date('d.m.Y') }} – {{ order.endDate|date('d.m.Y') }}
{% else %}
    od {{ order.startDate|date('d.m.Y') }}
{% endif %}
```

With:
```twig
{{ order.startDate|date('d.m.Y') }} – {{ (contract.endDate ?? order.endDate)|date('d.m.Y') }}
{% if order.rentalType.value == 'unlimited' %}
    <span class="text-xs text-gray-500">(prodlužuje se automaticky)</span>
{% endif %}
```

Note: for Order-only contexts (pre-payment), `endDate` might still be null (UNLIMITED order). In templates that only have `order` (not `contract`), show: "od DD.MM.YYYY (na dobu neurčitou s automatickým prodlužováním)."

**Files to update:**

1. `templates/admin/order/detail.html.twig` — lines 241-243, 266
2. `templates/admin/order/list.html.twig` — line 57
3. `templates/portal/user/order/detail.html.twig` — lines 130-132, 155, 307
4. `templates/portal/user/order/list.html.twig` — line 63
5. `templates/portal/landlord/order/detail.html.twig` — lines 126-128, 151
6. `templates/portal/landlord/order/list.html.twig` — line 79
7. `templates/portal/dashboard_user.html.twig` — line 103
8. `templates/portal/calendar/index.html.twig` — line 322
9. `templates/portal/storage/list.html.twig` — line 197
10. `templates/portal/storage_type/occupancy.html.twig` — line 172
11. `templates/public/order_status.html.twig` — line 224
12. `templates/public/order_payment.html.twig` — line 55
13. `templates/public/customer_signing.html.twig` — check for endDate display
14. `templates/components/OrderForm.html.twig` — lines 209, 220, 347
15. `templates/email/order_placed.html.twig` — endDate display
16. `templates/email/rental_activated.html.twig` — endDate display
17. `templates/email/signing_link.html.twig` — endDate display
18. `templates/email/contract_expiring.html.twig` — endDate display
19. `templates/portal/place/contracts.html.twig` — endDate display
20. `templates/portal/place/occupancy.html.twig` — endDate display
21. `templates/admin/operations/list.html.twig` — endDate display

For calendar, storage lists, and occupancy views that show "neomezeně ∞" for null endDate: replace with the actual endDate formatted, and add a small "↻" or "(automat.)" indicator for recurring contracts.

### 12. Form type labels

**File:** `src/Form/OrderFormType.php` (line 153)

Change: `RentalType::UNLIMITED => 'Na dobu neurčitou'` → `RentalType::UNLIMITED => 'Na dobu neurčitou (automaticky prodlužováno)'`

**File:** `src/Form/AdminOnboardingFormType.php` (line 100)

Change: `RentalType::UNLIMITED => 'Doba neurčitá'` → `RentalType::UNLIMITED => 'Automatické prodlužování'`

### 13. PlaceOccupancyMap isUnlimited check

**File:** `src/Twig/Components/PlaceOccupancyMap.php` (line 132)

Replace `null === $contract->endDate` with `$contract->isUnlimited()` (checks `RentalType::UNLIMITED`, which is the correct semantic check for whether the rental is indefinite-intent):

```php
'isUnlimited' => null !== $contract && $contract->isUnlimited() && null === $contract->terminatesAt,
```

### 14. Migration — backfill endDate for existing UNLIMITED contracts

Generate via `make:migration` (no schema change needed — `endDate` remains nullable). Add a data migration:

```sql
-- Backfill endDate for existing UNLIMITED contracts using the best available date
UPDATE contract
SET end_date = COALESCE(paid_through_date, next_billing_date, DATE_ADD(start_date, INTERVAL 1 MONTH))
WHERE end_date IS NULL
  AND rental_type = 'unlimited'
  AND payment_frequency = 'monthly';

UPDATE contract
SET end_date = COALESCE(paid_through_date, next_billing_date, DATE_ADD(start_date, INTERVAL 1 YEAR))
WHERE end_date IS NULL
  AND rental_type = 'unlimited'
  AND payment_frequency = 'yearly';
```

This is a data-only migration (no schema change), so create a standalone migration class with the SQL above. The `endDate` column stays nullable in the entity to support old data gracefully.

### 15. Customer portal termination UI text

**File:** `templates/portal/user/order/detail.html.twig` (line 307)

Replace "Smlouvu na dobu neurčitou můžete kdykoliv ukončit. Po ukončení bude skladová jednotka uvolněna." with:

"Opakované platby můžete kdykoliv zrušit. Smlouva skončí na konci aktuálně zaplaceného období ({{ contract.endDate|date('d.m.Y') }}). Po skončení bude skladová jednotka uvolněna."

### 16. AuditLogger — new log entry for recurring cancellation

**File:** `src/Service/AuditLogger.php`

Add `logContractRecurringCancelled(Contract $contract)` method mirroring existing `logContractTerminated` style.

### 17. StorageRentalView — update isUnlimited

**File:** `src/Value/StorageRentalView.php` (line 45)

The `isUnlimited` property currently checks `endDate === null` in its factory. Update to check `rentalType === UNLIMITED` instead.

### 18. Order entity — no change to endDate

`Order.endDate` stays null for UNLIMITED. The Order represents the customer's intent (indefinite rental). The contract-level endDate computation (requirement 1) is where the legal reality is established.

## Acceptance

- [ ] New UNLIMITED contracts always have `endDate` set (never null). Value = `startDate + billingCadenceStep`.
- [ ] Contract DOCX always says "Nájem se sjednává na dobu určitou, a to od X do Y" — never "na dobu neurčitou."
- [ ] Successful recurring billing charge advances `Contract.endDate` to match `paidThroughDate`.
- [ ] `Contract.isActive()` returns true for recurring contracts past endDate while billing is in grace (active GoPay token for AUTO, pending billing for MANUAL).
- [ ] `ProcessContractTerminationsCommand` does NOT terminate recurring contracts that are in billing grace.
- [ ] `SendExpirationRemindersCommand` does NOT send reminders for contracts that will auto-extend.
- [ ] Customer can terminate a recurring contract from portal → recurring cancelled → contract expires at current endDate. Flash message shows the expiry date.
- [ ] `ContractVoter::TERMINATE` gates on `billingMode->isRecurring()` (not `isUnlimited()`).
- [ ] `OrderRenewController` accepts UNLIMITED orders for renewal (no redirect/flash block).
- [ ] All ~20 templates display "Na dobu určitou" or actual date ranges instead of "Na dobu neurčitou."
- [ ] UNLIMITED contracts get long-term pricing (`isLongTermMonthly()` returns true regardless of endDate).
- [ ] Existing UNLIMITED contracts have endDate backfilled via migration.
- [ ] `composer quality` is green.

## Out of scope

- **LIMITED + recurring auto-extension** — VOP §IV applies to all recurring contracts, not just UNLIMITED. After a LIMITED contract's endDate passes with active recurring, it should auto-extend. This is a behavioral change to the LIMITED flow and deserves its own spec. Currently, LIMITED contracts expire and require manual renewal via `OrderRenewController`.
- **Re-generating existing contract documents** — Old contracts keep their "na dobu neurčitou" text. Only new contracts get the updated text.
- **Order form UI label for "Neomezeně"** — The order form radio keeps the customer-friendly "Neomezeně" or "Na dobu neurčitou (automaticky prodlužováno)" label. The legal distinction happens at the contract level, not the order intent level. Minor label update in requirement 12 is sufficient.
- **Settle-on-cancel (spec 019)** — When the customer revokes recurring mid-period, any prorated charge for the used portion of the current period is not handled here.
- **Admin contract re-opening / re-activation** — No mechanism to reactivate an expired contract.

## Open questions

None — proceed.
