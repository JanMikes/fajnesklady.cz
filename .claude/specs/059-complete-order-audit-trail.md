# 059 — Complete order/contract audit trail with unified timeline UI

**Status:** ready
**Type:** feature / observability
**Scope:** medium (~15 modified files, 1 new template partial, 1 migration)
**Depends on:** none

## Problem

The audit log has significant blind spots. Several order/contract lifecycle actions are silently executed without an `AuditLog` entry:

- **Recurring billing charge success** (AUTO_RECURRING via `ChargeRecurringPaymentHandler`) — the most important financial event — is never audited.
- **Recurring billing failure** (`ProcessRecurringPaymentsCommand::recordFailure()`, `RetryFailedPaymentsCommand`) only writes to the app logger, not the audit log.
- **Fine issued / cancelled / paid via GoPay** — the entire fine lifecycle (spec 053) has zero audit coverage (bank-transfer match is the only audited path).
- **Handover protocol created / tenant submitted / landlord submitted** — no audit entries.
- **Admin manual email sends** (4 controllers from spec 056: resend signing link, onboarding reminder, billing reminder, fine reminder) — no audit entries.
- **Payment demand sent** ("Výzva k úhradě", spec 055) — no audit entry.

Additionally, while `AuditLogRepository::findForOrderTimeline()` exists, it is **never called** — no UI surfaces the order-level audit trail. The admin order detail shows email history (spec 056) but not the audit log. And the `AuditLog` entity has no `orderId`/`userId` FK, making it impossible to efficiently query "all events related to this order" or "all events related to this customer" across entity types (order + contract + storage + fine + manual_payment_request).

## Goal

1. Every order/contract/fine lifecycle action produces an `AuditLog` entry — no silent mutations.
2. Admin order detail gains a **"Historie aktivity"** timeline panel merging `AuditLog` + `EmailLog` chronologically.
3. `AuditLog` gains nullable `orderId` and `userId` FKs (mirrors spec 056's `EmailLog.orderId` pattern) enabling efficient cross-entity queries and a future admin user detail page.
4. Admin audit log list page gains an `orderId` filter (like `EmailLog` already has).

## Context (current state)

### AuditLogger service
- `src/Service/AuditLogger.php` — 17 dedicated `log*()` methods + 1 generic `log()`. Already injects `Security`, `RequestStack`, `ClockInterface`, `ProvideIdentity`.
- The generic `log()` method (line 320) accepts `entityType`, `entityId`, `eventType`, `payload`. It does NOT accept `orderId` or `userId`.

### AuditLog entity
- `src/Entity/AuditLog.php` — fields: `id`, `entityType`, `entityId`, `eventType`, `payload` (JSON), `user` (nullable FK), `ipAddress`, `createdAt`. No `orderId` or `userId` fields.
- Index on `(entity_type, entity_id)`.

### AuditLogRepository
- `src/Repository/AuditLogRepository.php` — has `findForOrderTimeline(orderId, ?contractId)` (line 162) that queries by `entityType=order|contract` + matching IDs. **Never called from any controller or template.**
- `findPaginatedWithFilters()` (line 98) supports `entityType`, `eventType`, `search` filters — no `orderId` filter.

### Gaps — handlers missing audit calls

| Handler / Controller | Action | File |
|---|---|---|
| `ChargeRecurringPaymentHandler::finalizeCharge()` | Recurring charge success | `src/Command/ChargeRecurringPaymentHandler.php:171` |
| `ProcessRecurringPaymentsCommand::recordFailure()` | Recurring charge failure | `src/Console/ProcessRecurringPaymentsCommand.php:88` |
| `RetryFailedPaymentsCommand` | Retry failure | `src/Console/RetryFailedPaymentsCommand.php:119` |
| `IssueFineHandler::__invoke()` | Fine issued | `src/Command/IssueFineHandler.php:29` |
| `CancelFineHandler::__invoke()` | Fine cancelled | `src/Command/CancelFineHandler.php:22` |
| `ProcessPaymentNotificationHandler` (line 144) | Fine paid via GoPay | `src/Command/ProcessPaymentNotificationHandler.php:144` |
| `CreateHandoverProtocolHandler::__invoke()` | Handover created | `src/Command/CreateHandoverProtocolHandler.php` |
| `CompleteTenantHandoverHandler::__invoke()` | Tenant submitted handover | `src/Command/CompleteTenantHandoverHandler.php` |
| `CompleteLandlordHandoverHandler::__invoke()` | Landlord submitted handover | `src/Command/CompleteLandlordHandoverHandler.php` |
| `AdminOrderResendSigningLinkController` | Admin resent signing link | `src/Controller/Admin/AdminOrderResendSigningLinkController.php` |
| `AdminOrderSendOnboardingReminderController` | Admin sent onboarding reminder | `src/Controller/Admin/AdminOrderSendOnboardingReminderController.php` |
| `AdminOrderSendBillingReminderController` | Admin sent billing reminder | `src/Controller/Admin/AdminOrderSendBillingReminderController.php` |
| `AdminOrderSendFineReminderController` | Admin sent fine reminder | `src/Controller/Admin/AdminOrderSendFineReminderController.php` |
| `RetryFailedPaymentsCommand` (line 125) | Payment demand sent | `src/Console/RetryFailedPaymentsCommand.php:125` |

### Admin order detail
- `src/Controller/Admin/AdminOrderDetailController.php` — already loads `emailLogs` via `EmailLogRepository::findByOrderId()`. Does NOT load audit log entries.
- `templates/admin/order/detail.html.twig:587` — "E-mailová komunikace" section shows email history table.

### Admin audit log page
- `src/Controller/Admin/AdminAuditLogController.php` — filters: `entity_type`, `event_type`, `search`. No `orderId` filter.

### No admin user detail page
- There is no `/portal/admin/users/{id}` page. The user list is at `src/Controller/Portal/UserListController.php`. User-scoped audit display is a future opportunity once the `userId` FK exists on `AuditLog`.

## Requirements

### 1. Add `orderId` and `userId` columns to `AuditLog`

**File:** `src/Entity/AuditLog.php`

Add two nullable indexed columns:

```php
#[ORM\Column(type: UuidType::NAME, nullable: true)]
#[ORM\Index(columns: ['order_id'], name: 'audit_order_idx')]
private(set) ?Uuid $orderId,

#[ORM\Column(type: UuidType::NAME, nullable: true)]
#[ORM\Index(columns: ['user_id_context'], name: 'audit_user_context_idx')]
private(set) ?Uuid $userIdContext,
```

Use `userIdContext` (not `userId`) to avoid confusion with the existing `user` FK (which is "who performed the action"). `userIdContext` means "which customer is this event about."

Add to the constructor as the last two parameters with defaults of `null`.

### 2. Extend `AuditLogger::log()` to accept `orderId` and `userIdContext`

**File:** `src/Service/AuditLogger.php`

Add optional parameters to the generic `log()` method:

```php
public function log(
    string $entityType,
    string $entityId,
    string $eventType,
    array $payload = [],
    ?Uuid $orderId = null,
    ?Uuid $userIdContext = null,
): void
```

Pass them through to the `AuditLog` constructor.

Update all existing dedicated `log*()` methods to pass `orderId` and `userIdContext` where the information is available:
- `logOrderCreated($order)` → `orderId: $order->id, userIdContext: $order->user->id`
- `logOrderReserved($order)` → `orderId: $order->id, userIdContext: $order->user->id`
- `logOrderPaid($order)` → `orderId: $order->id, userIdContext: $order->user->id`
- `logOrderCompleted($order)` → `orderId: $order->id, userIdContext: $order->user->id`
- `logOrderCancelled($order)` → `orderId: $order->id, userIdContext: $order->user->id`
- `logOrderSigned($order)` → `orderId: $order->id, userIdContext: $order->user->id`
- `logOrderExpired($order)` → `orderId: $order->id, userIdContext: $order->user->id`
- `logBillingModeSetOnOrder($order)` → `orderId: $order->id, userIdContext: $order->user->id`
- `logContractCreated($contract)` → `orderId: $contract->order->id, userIdContext: $contract->user->id`
- `logContractSigned($contract)` → `orderId: $contract->order->id, userIdContext: $contract->user->id`
- `logContractTerminated($contract)` → `orderId: $contract->order->id, userIdContext: $contract->user->id`
- `logContractExpiringSoon($contract)` → `orderId: $contract->order->id, userIdContext: $contract->user->id`
- `logContractRecurringCancelled($contract)` → `orderId: $contract->order->id, userIdContext: $contract->user->id`
- `logStorageReserved($storage, $order)` → `orderId: $order->id, userIdContext: $order->user->id`
- `logStorageOccupied($storage, $contract)` → `orderId: $contract->order->id, userIdContext: $contract->user->id`
- `logStorageReleased($storage)` → leave `null` (no order/user context available from storage alone)
- `logManualPaymentRequested($request)` → `orderId: $request->contract->order->id, userIdContext: $request->contract->user->id`
- `logManualPaymentReceived($request)` → `orderId: $request->contract->order->id, userIdContext: $request->contract->user->id`

Also update all existing generic `log()` call sites across the codebase that pass order/contract context (e.g. in `ProcessIncomingBankTransactionHandler`, `AdminTerminateContractHandler`, etc.) to include the new `orderId` / `userIdContext` parameters.

### 3. Add missing audit calls — recurring billing

**File:** `src/Command/ChargeRecurringPaymentHandler.php`

Inject `AuditLogger`. In `finalizeCharge()` (line 171), after `$contract->recordBillingCharge()`:

```php
$this->auditLogger->log(
    entityType: 'contract',
    entityId: $contract->id->toRfc4122(),
    eventType: 'recurring_charged',
    payload: [
        'gopay_payment_id' => $paymentId,
        'amount' => $amount,
        'billing_mode' => 'auto_recurring',
        'next_billing_date' => $nextBillingDate?->format('Y-m-d'),
        'paid_through_date' => $paidThroughDate->format('Y-m-d'),
    ],
    orderId: $contract->order->id,
    userIdContext: $contract->user->id,
);
```

**File:** `src/Console/ProcessRecurringPaymentsCommand.php`

Inject `AuditLogger`. In `recordFailure()` (line 88), after `$contract->recordFailedBillingAttempt()`:

```php
$this->auditLogger->log(
    entityType: 'contract',
    entityId: $contract->id->toRfc4122(),
    eventType: 'recurring_payment_failed',
    payload: [
        'attempt' => $contract->failedBillingAttempts,
        'reason' => $exception->getMessage(),
    ],
    orderId: $contract->order->id,
    userIdContext: $contract->user->id,
);
```

**File:** `src/Console/RetryFailedPaymentsCommand.php`

Inject `AuditLogger`. After each retry failure (line 119 area), add the same `recurring_payment_failed` audit call.

Also at line 125 (payment demand sent), add:

```php
$this->auditLogger->log(
    entityType: 'contract',
    entityId: $contract->id->toRfc4122(),
    eventType: 'payment_demand_sent',
    payload: ['attempt' => $contract->failedBillingAttempts],
    orderId: $contract->order->id,
    userIdContext: $contract->user->id,
);
```

### 4. Add missing audit calls — fines

**File:** `src/Command/IssueFineHandler.php`

Inject `AuditLogger`. After `$this->fineRepository->save($fine)` (line 60):

```php
$this->auditLogger->log(
    entityType: 'fine',
    entityId: $fineId->toRfc4122(),
    eventType: 'issued',
    payload: [
        'contract_id' => $contract->id->toRfc4122(),
        'type' => $command->type->value,
        'amount' => $command->amountInHaler,
        'issued_by' => $admin->id->toRfc4122(),
    ],
    orderId: $contract->order->id,
    userIdContext: $user->id,
);
```

**File:** `src/Command/CancelFineHandler.php`

Inject `AuditLogger`. After `$fine->cancel()` (line 38):

```php
$this->auditLogger->log(
    entityType: 'fine',
    entityId: $command->fineId->toRfc4122(),
    eventType: 'cancelled',
    payload: [
        'cancelled_by' => $admin->id->toRfc4122(),
    ],
    orderId: $fine->contract->order->id,
    userIdContext: $fine->user->id,
);
```

**File:** `src/Command/ProcessPaymentNotificationHandler.php`

After `$fine->markPaid($now)` (line 144):

```php
$this->auditLogger->log(
    entityType: 'fine',
    entityId: $fine->id->toRfc4122(),
    eventType: 'paid',
    payload: [
        'payment_method' => 'gopay',
        'gopay_payment_id' => $command->paymentId,
    ],
    orderId: $fine->contract->order->id,
    userIdContext: $fine->user->id,
);
```

### 5. Add missing audit calls — handover protocol

**File:** `src/Command/CreateHandoverProtocolHandler.php`

Inject `AuditLogger`. After creating and saving the handover protocol:

```php
$this->auditLogger->log(
    entityType: 'handover',
    entityId: $protocol->id->toRfc4122(),
    eventType: 'created',
    payload: ['contract_id' => $contract->id->toRfc4122()],
    orderId: $contract->order->id,
    userIdContext: $contract->user->id,
);
```

**File:** `src/Command/CompleteTenantHandoverHandler.php`

Inject `AuditLogger`. After completing tenant side:

```php
$this->auditLogger->log(
    entityType: 'handover',
    entityId: $protocol->id->toRfc4122(),
    eventType: 'tenant_submitted',
    payload: ['status' => $protocol->status->value],
    orderId: $protocol->contract->order->id,
    userIdContext: $protocol->contract->user->id,
);
```

**File:** `src/Command/CompleteLandlordHandoverHandler.php`

Inject `AuditLogger`. After completing landlord side:

```php
$this->auditLogger->log(
    entityType: 'handover',
    entityId: $protocol->id->toRfc4122(),
    eventType: 'landlord_submitted',
    payload: ['status' => $protocol->status->value],
    orderId: $protocol->contract->order->id,
    userIdContext: $protocol->contract->user->id,
);
```

### 6. Add missing audit calls — admin manual email sends

**Files:** All 4 manual-send controllers:
- `src/Controller/Admin/AdminOrderResendSigningLinkController.php`
- `src/Controller/Admin/AdminOrderSendOnboardingReminderController.php`
- `src/Controller/Admin/AdminOrderSendBillingReminderController.php`
- `src/Controller/Admin/AdminOrderSendFineReminderController.php`

Inject `AuditLogger` into each. After the dispatch call (before flash + redirect), add:

```php
$this->auditLogger->log(
    entityType: 'order',
    entityId: $order->id->toRfc4122(),
    eventType: 'admin_manual_email_sent',
    payload: ['email_type' => 'signing_link'], // or 'onboarding_reminder', 'billing_reminder', 'fine_reminder'
    orderId: $order->id,
    userIdContext: $order->user->id,
);
```

### 7. Update `AuditLogRepository` — orderId + userIdContext queries

**File:** `src/Repository/AuditLogRepository.php`

**a)** Update `findForOrderTimeline()` to use the new `orderId` column instead of the fragile `entityType + entityId` matching:

```php
public function findForOrderTimeline(Uuid $orderId): array
{
    return $this->entityManager->createQueryBuilder()
        ->select('al')
        ->from(AuditLog::class, 'al')
        ->where('al.orderId = :orderId')
        ->setParameter('orderId', $orderId)
        ->orderBy('al.createdAt', 'ASC')
        ->getQuery()
        ->getResult();
}
```

**b)** Add `findByUserIdContext()`:

```php
/**
 * @return AuditLog[]
 */
public function findByUserIdContext(Uuid $userId, int $limit = 100): array
{
    return $this->entityManager->createQueryBuilder()
        ->select('al')
        ->from(AuditLog::class, 'al')
        ->where('al.userIdContext = :userId')
        ->setParameter('userId', $userId)
        ->orderBy('al.createdAt', 'DESC')
        ->setMaxResults($limit)
        ->getQuery()
        ->getResult();
}
```

**c)** Extend `findPaginatedWithFilters()` and `countWithFilters()` to accept optional `?Uuid $orderId` parameter:

```php
if (null !== $orderId) {
    $qb->andWhere('al.orderId = :orderId')
        ->setParameter('orderId', $orderId);
}
```

### 8. Admin order detail — "Historie aktivity" timeline panel

**File:** `src/Controller/Admin/AdminOrderDetailController.php`

Inject `AuditLogRepository`. Load audit entries:

```php
$auditLogs = $this->auditLogRepository->findForOrderTimeline($order->id);
```

Pass `auditLogs` to template.

**File:** `templates/admin/order/detail.html.twig`

Add a new panel below the "E-mailová komunikace" section. Merge `auditLogs` and `emailLogs` chronologically via Twig. New partial: `templates/admin/order/_activity_timeline.html.twig`.

Timeline item format:
```
[icon] [timestamp] [event label] [actor badge] [detail]
```

Where:
- **icon**: colour-coded by category (green = payment, red = failure/cancel, blue = info, amber = warning)
- **event label**: Czech translation of `eventType` (see mapping below)
- **actor badge**: `user.email` or "Systém" for cron actions (null user)
- **detail**: condensed one-liner from payload (e.g. "5 000 Kč, GoPay #123456")

Event type → Czech label mapping (new `AuditEventLabel` helper or inline Twig map):

| eventType | Label |
|---|---|
| `created` | Objednávka vytvořena |
| `reserved` | Sklad rezervován |
| `signed` | Podepsáno |
| `paid` | Platba přijata |
| `completed` | Objednávka dokončena |
| `cancelled` | Zrušeno |
| `expired` | Vypršelo |
| `billing_mode_set` | Způsob platby nastaven |
| `contract.created` | Smlouva vytvořena |
| `contract.signed` | Smlouva podepsána |
| `contract.terminated` | Smlouva ukončena |
| `contract.expiring_soon` | Upozornění na blížící se konec |
| `recurring_cancelled` | Opakované platby zrušeny |
| `recurring_charged` | Opakovaná platba stržena |
| `recurring_payment_failed` | Platba selhala |
| `payment_demand_sent` | Výzva k úhradě odeslána |
| `fine.issued` | Pokuta vystavena |
| `fine.cancelled` | Pokuta zrušena |
| `fine.paid` | Pokuta uhrazena |
| `handover.created` | Předávací protokol vytvořen |
| `handover.tenant_submitted` | Protokol — nájemce vyplnil |
| `handover.landlord_submitted` | Protokol — pronajímatel vyplnil |
| `admin_manual_email_sent` | Manuální e-mail odeslán |
| `storage.occupied` | Sklad obsazen |
| `storage.released` | Sklad uvolněn |
| `storage.reserved` | Sklad rezervován |

Email log entries (merged into same timeline) get a mail icon + "E-mail: {subject}" label + link to email log detail.

### 9. Admin audit log page — orderId filter

**File:** `src/Controller/Admin/AdminAuditLogController.php`

Accept `?string $orderId` from query params, convert to `?Uuid`, pass to the updated `findPaginatedWithFilters()`.

**File:** `templates/admin/audit_log/list.html.twig`

Add an "Objednávka" text input field to the filter bar. Pre-fill when `orderId` is in the URL (e.g. when clicking "Zobrazit vše →" from the order detail timeline).

### 10. Migration — add columns + backfill

Generate via `make:migration`.

Schema changes:
- `ALTER TABLE audit_log ADD order_id CHAR(36) DEFAULT NULL`
- `ALTER TABLE audit_log ADD user_id_context CHAR(36) DEFAULT NULL`
- `CREATE INDEX audit_order_idx ON audit_log (order_id)`
- `CREATE INDEX audit_user_context_idx ON audit_log (user_id_context)`

**Backfill** (best-effort, data-only):

```sql
-- Backfill orderId for order-type entries
UPDATE audit_log SET order_id = entity_id WHERE entity_type = 'order' AND order_id IS NULL;

-- Backfill orderId for contract-type entries via join
UPDATE audit_log al
    INNER JOIN contract c ON al.entity_id = c.id
SET al.order_id = c.order_id
WHERE al.entity_type = 'contract' AND al.order_id IS NULL;

-- Backfill userIdContext for order-type entries via join
UPDATE audit_log al
    INNER JOIN `order` o ON al.entity_id = o.id
SET al.user_id_context = o.user_id
WHERE al.entity_type = 'order' AND al.user_id_context IS NULL;

-- Backfill userIdContext for contract-type entries via join
UPDATE audit_log al
    INNER JOIN contract c ON al.entity_id = c.id
SET al.user_id_context = c.user_id
WHERE al.entity_type = 'contract' AND al.user_id_context IS NULL;
```

Entries for `storage`, `manual_payment_request`, `bank_transaction`, `fine` etc. remain un-backfilled (acceptable — new entries going forward will have the FKs; historical ones can be resolved via `payload` JSON if needed).

### 11. Admin audit log export — include new columns

**File:** `src/Controller/Admin/AdminAuditLogExportController.php`

Add `orderId` and `userIdContext` columns to the Excel export.

## Acceptance

- [ ] All 14 gap actions listed in Context now produce an `AuditLog` entry.
- [ ] `AuditLog` entity has `orderId` (nullable UUID) and `userIdContext` (nullable UUID), both indexed.
- [ ] Every dedicated `log*()` method on `AuditLogger` passes `orderId` + `userIdContext` where available.
- [ ] Admin order detail shows a "Historie aktivity" timeline panel merging audit + email events chronologically.
- [ ] Timeline items have Czech labels, colour-coded icons, and actor attribution.
- [ ] Admin audit log list page has an "Objednávka" filter field that filters by `orderId`.
- [ ] Migration backfills `orderId` and `userIdContext` for existing `order` and `contract` type entries.
- [ ] Excel export of audit log includes the two new columns.
- [ ] `composer quality` is green.

## Out of scope

- **Admin user detail page** — no `/portal/admin/users/{id}` exists yet. The `userIdContext` FK is ready for it, but building the page is a separate spec. The user list page could gain a "Historie" link per user in a follow-up.
- **Backfill for non-order/contract entity types** — `storage`, `fine`, `manual_payment_request`, `bank_transaction` entries won't have `orderId`/`userIdContext` backfilled for historical data. Only new entries will have them.
- **Audit for admin onboarding billing mode + price override** — `logBillingModeSetOnOrder()` already exists (it was added in a prior spec but the admin onboarding handler doesn't call it). Wiring this up is **in scope** (requirement 2 — updating all existing methods to pass the new FK params covers call-site review, and this gap should be closed at that time). If the handler currently skips the call, add it.
- **Customer-facing audit trail** — customers don't see audit log data. The timeline is admin-only.
- **Audit log retention / archival** — no TTL or cleanup policy.

## Open questions

None — proceed.
