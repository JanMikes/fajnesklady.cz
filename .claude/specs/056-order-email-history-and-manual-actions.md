# 056 — Order email history and manual send actions on admin order detail

**Status:** in-progress
**Type:** feature
**Scope:** medium (~8 new files, ~6 modified)
**Depends on:** none

## Problem

Admin has no visibility into which emails were sent for a specific order. The email log exists (`/portal/admin/email-log`) but is global — there's no way to filter by order. When a customer reports they didn't receive an email, the admin has to manually search by recipient + date. Additionally, cron-triggered emails (payment reminders, signing links, billing requests) cannot be re-sent manually — the admin has to wait for the next cron cycle or ask a developer.

## Goal

1. **Email history** — the admin order detail page (`/portal/admin/orders/{id}`) shows an inline table of all emails sent in relation to that order (date, type, status, recipient). Each row links to the existing email log detail page.
2. **Manual send actions** — admin can manually trigger four email types from the order detail: resend signing link, onboarding payment reminder, manual billing payment request, and fine payment reminder. Each sends exactly the same email the cron/event handler would, bypassing cron idempotency guards.
3. **Order→EmailLog linking** — a new nullable `orderId` FK on `EmailLog` ties future emails to their source order. The link is set via a custom `X-Order-Id` header on `TemplatedEmail`, read by `EmailLogger` at persist time.

## Context (current state)

### EmailLog entity — no order relation
- `src/Entity/EmailLog.php` — 16 columns, no FK to Order. Written by `EmailLogger` via Symfony mailer events.
- `src/Service/EmailLogger.php` — listens to `MessageEvent` (priority 256, captures template name), `SentMessageEvent`, `FailedMessageEvent`. Calls `EmailLogRepository::save()` which flushes immediately (documented exception to no-flush rule).
- `src/Repository/EmailLogRepository.php` — `save()`, `findPaginated()`, `countWithFilter()`, `streamWithFilter()`. Filter via `EmailLogFilter` VO (date, recipient, subject, template, status).

### Admin order detail — stacked-card layout
- `src/Controller/Admin/AdminOrderDetailController.php` — loads order + contract + invoices + handover + fines + priceChanges + paymentSchedule. Renders `admin/order/detail.html.twig` (586 lines, no tabs).
- Template uses stacked white cards with `border-b border-gray-200` dividers.

### Email handlers that need the `X-Order-Id` header
Every handler below constructs a `TemplatedEmail` and has access to the Order (directly or via Contract):
- `SendSigningLinkEmailHandler` — listens `AdminOnboardingInitiated`, needs `signingToken`
- `SendOnboardingPaymentReminderEmailHandler` — listens `OnboardingPaymentReminderRequested(orderId, stage)`
- `SendOrderPlacedEmailHandler` — listens `OrderPlaced`
- `SendOrderCancelledEmailHandler` — listens `OrderCancelled`
- `SendRentalActivatedEmailHandler` — listens `OrderCompleted`
- `SendInvoiceEmailHandler` — listens `InvoiceCreated`
- `SendManualBillingPaymentRequestedEmailHandler` — listens `ManualBillingPaymentRequested(contractId, manualPaymentRequestId, stage)`
- `SendManualBillingPaymentOverdueEmailHandler` — listens `ManualBillingPaymentOverdue`
- `SendManualBillingOverdueAdminEmailHandler` — listens `ManualBillingPaymentOverdue`
- `SendRecurringPaymentEstablishedEmailHandler` — listens `RecurringPaymentEstablished`
- `SendRecurringPaymentFailedEmailHandler` — listens `RecurringPaymentFailed`
- `SendRecurringPaymentFailedAdminEmailHandler` — listens `RecurringPaymentFailed`
- `SendRecurringPaymentAdvanceNoticeEmailHandler` — listens `RecurringPaymentAdvanceNoticeNeeded`
- `SendRecurringPaymentCancelledEmailHandler` — listens `RecurringPaymentCancelled`
- `SendRecurringPaymentCancelledAdminEmailHandler` — listens `RecurringPaymentCancelled`
- `SendContractExpiringReminderHandler` — listens `ContractExpiringSoon`
- `SendContractTerminatedEmailHandler` — listens `ContractTerminated`
- `SendTerminationNoticeEmailHandler` — listens `TerminationNoticeRequested`
- `SendTerminationNoticeAdminEmailHandler` — listens `TerminationNoticeRequested`
- `SendPaymentDefaultEmailHandler` — listens `RecurringPaymentFailed` (D+3 default notice)
- `SendPaymentDemandEmailHandler` — listens `PaymentDemandRequested`
- `SendPaymentDemandAdminEmailHandler` — listens `PaymentDemandRequested`
- `SendExternalPrepaymentEndingSoonEmailHandler` — listens `ExternalPrepaymentEndingSoon`
- `SendDebtPaymentRequestEmailHandler` — listens `OnboardingDebtPaymentRequested`
- `SendFineIssuedEmailHandler` — listens `FineIssued`
- `SendFinePaymentReminderEmailHandler` — listens `FinePaymentReminderRequested(fineId, stage)`
- `SendFinePaidEmailHandler` — listens `FinePaid`
- `SendAmountMismatchAlertEmailHandler` — listens `PaymentAmountMismatch`

### Signing link resend prerequisites
- `Order.signingToken` (`src/Entity/Order.php:87`) — set by `AdminOnboardingHandler`, nullable. The handler `SendSigningLinkEmailHandler` listens to `AdminOnboardingInitiated` which carries `signingToken`. For a manual resend, we read the token from the Order entity directly — it persists after onboarding.

### Manual billing prerequisites
- `ManualPaymentRequest` entity — one per `(contract, periodStart)`. Has `goPayGatewayUrl`, `amount`, `periodStart`, `periodEnd`. Retrieved via `ManualPaymentRequestRepository::findPendingForCurrentCycle(contract, now)`.
- `ManualBillingPaymentRequested` event carries `contractId`, `manualPaymentRequestId`, `stage`.

### Fine prerequisites
- `Fine` entity — `isPayable()` guard. Retrieved via `FineRepository::findByContract(contract)`.
- `FinePaymentReminderRequested` event carries `fineId`, `stage`.

## Architecture

```
┌──────────────────────────────────────────────────────────┐
│  Email handlers (28 handlers)                            │
│  ┌────────────────────────────────┐                      │
│  │ $email->getHeaders()->addTextHeader(                  │
│  │   'X-Order-Id', $order->id->toRfc4122()              │
│  │ );                                                    │
│  └─────────────┬──────────────────┘                      │
│                │                                         │
│                ▼                                         │
│  EmailLogger::buildLog()                                 │
│  ┌────────────────────────────────┐                      │
│  │ reads X-Order-Id header →      │                      │
│  │ passes orderId to EmailLog     │                      │
│  └─────────────┬──────────────────┘                      │
│                │                                         │
│                ▼                                         │
│  EmailLog (new nullable orderId FK)                      │
│                │                                         │
│                ▼                                         │
│  EmailLogRepository::findByOrderId()                     │
│                │                                         │
│                ▼                                         │
│  AdminOrderDetailController (renders inline table)       │
└──────────────────────────────────────────────────────────┘

Manual actions:
  Admin clicks button → POST AdminOrderResend*Controller
    → dispatches event on event bus → handler sends email
    → EmailLogger captures it with X-Order-Id → visible in table
```

## Requirements

### 1. Add nullable `orderId` to `EmailLog` entity

**File:** `src/Entity/EmailLog.php`

Add a nullable `orderId` column (UUID, indexed). NOT a Doctrine ManyToOne relation — `EmailLog` writes out-of-band (its own flush), so we avoid lazy-loading / proxy issues. Store the raw UUID.

```php
#[ORM\Column(type: UuidType::NAME, nullable: true)]
#[ORM\Index(columns: ['order_id'], name: 'email_log_order_id_idx')]
private(set) ?Uuid $orderId,
```

Add it as the last constructor parameter with default `null`. The `#[ORM\Index]` goes at class level.

Generate migration via `docker compose exec web bin/console make:migration`.

### 2. Read `X-Order-Id` header in `EmailLogger`

**File:** `src/Service/EmailLogger.php`

In `buildLog()`, after extracting the template name, read the custom header:

```php
$orderId = null;
$orderIdHeader = $message->getHeaders()->get('X-Order-Id');
if (null !== $orderIdHeader) {
    try {
        $orderId = Uuid::fromString($orderIdHeader->getBodyAsString());
    } catch (\InvalidArgumentException) {
        // Malformed header — skip silently.
    }
}
```

Pass `$orderId` to the `new EmailLog(...)` constructor.

### 3. Add `X-Order-Id` header to all order-related email handlers

**Files:** Every `Send*EmailHandler` listed in Context that has access to an Order (directly or via `$contract->order`).

Pattern — add one line before `$this->mailer->send($email)`:

```php
$email->getHeaders()->addTextHeader('X-Order-Id', $order->id->toRfc4122());
```

For handlers that access Order through a Contract (`$contract->order`), use that path. For handlers where the Order isn't loaded but could be (e.g., `SendFinePaymentReminderEmailHandler` has `$fine->contract->order`), load through the relation chain.

Handlers that are NOT order-scoped (welcome, verification, password reset, place access, place proposed, overdue digest) stay untouched.

### 4. Add `findByOrderId()` to `EmailLogRepository`

**File:** `src/Repository/EmailLogRepository.php`

```php
/**
 * @return EmailLog[]
 */
public function findByOrderId(Uuid $orderId, int $limit = 50): array
{
    return $this->entityManager->createQueryBuilder()
        ->select('e')
        ->from(EmailLog::class, 'e')
        ->where('e.orderId = :orderId')
        ->setParameter('orderId', $orderId)
        ->orderBy('e.attemptedAt', 'DESC')
        ->setMaxResults($limit)
        ->getQuery()
        ->getResult();
}
```

### 5. Email history section on admin order detail

**File:** `src/Controller/Admin/AdminOrderDetailController.php`

Inject `EmailLogRepository`. Add to template vars:

```php
'emailLogs' => $this->emailLogRepository->findByOrderId($order->id),
```

**File:** `templates/admin/order/detail.html.twig`

Add a new card section titled "E-mailová komunikace" after the invoices section. Table columns:
- **Datum** — `attemptedAt` formatted `d.m.Y H:i`
- **Typ** — `templateName` mapped to a human-readable Czech label (see label map below)
- **Příjemce** — first `toAddresses[0].email`
- **Stav** — green badge "Odesláno" / red badge "Selhalo"
- **Akce** — link icon to `admin_email_log_detail` (existing route)

If the list is empty, show grey text: "Zatím nebyla odeslána žádná e-mailová komunikace k této objednávce." with a note that only emails sent after this feature was deployed are tracked.

**Template name → Czech label map** (use a Twig macro or inline map):

| templateName | Label |
|---|---|
| `email/signing_link` | Odkaz k podpisu |
| `email/order_placed` | Potvrzení objednávky |
| `email/order_cancelled` | Zrušení objednávky |
| `email/rental_activated` | Aktivace pronájmu |
| `email/invoice` | Faktura |
| `email/onboarding_payment_reminder` | Připomínka platby (onboarding) |
| `email/manual_billing_payment_initial` | Výzva k platbě (7 dní) |
| `email/manual_billing_payment_reminder` | Připomínka platby (2 dny) |
| `email/manual_billing_payment_due_today` | Platba splatná dnes |
| `email/manual_billing_payment_overdue_first` | Upomínka — platba po splatnosti |
| `email/manual_billing_payment_overdue_final` | Poslední upomínka |
| `email/manual_billing_overdue_admin` | Upomínka (admin) |
| `email/recurring_payment_established` | Nastavení opakované platby |
| `email/recurring_payment_failed` | Selhání opakované platby |
| `email/recurring_payment_failed_admin` | Selhání platby (admin) |
| `email/recurring_payment_advance_notice` | Předběžné oznámení platby |
| `email/recurring_payment_cancelled` | Zrušení opakované platby |
| `email/recurring_payment_cancelled_admin` | Zrušení platby (admin) |
| `email/contract_expiring` | Blížící se konec smlouvy |
| `email/contract_terminated` | Ukončení smlouvy |
| `email/termination_notice` | Výpověď |
| `email/termination_notice_admin` | Výpověď (admin) |
| `email/payment_default_tenant` | Oznámení o prodlení |
| `email/payment_default_admin` | Prodlení (admin) |
| `email/payment_demand_tenant` | Výzva k úhradě |
| `email/payment_demand_admin` | Výzva k úhradě (admin) |
| `email/external_prepayment_ending_soon` | Končící externí předplatné |
| `email/external_prepayment_ending_soon_admin` | Končící předplatné (admin) |
| `email/debt_payment_request` | Výzva k úhradě dluhu |
| `email/fine_issued` | Vystavení pokuty |
| `email/fine_payment_reminder` | Připomínka pokuty |
| `email/fine_paid` | Potvrzení úhrady pokuty |
| `email/payment_amount_mismatch` | Nesouhlasí částka platby |

Fallback for unknown: display the raw `templateName` value.

### 6. Manual action controllers

Four new single-action controllers, all `ROLE_ADMIN`, all POST-only with CSRF token. Each dispatches the appropriate event on the event bus and redirects back to `admin_order_detail` with a flash message. These bypass cron idempotency guards because the admin explicitly chose to send.

#### 6a. Resend signing link

**New file:** `src/Controller/Admin/AdminOrderResendSigningLinkController.php`

- **Route:** `POST /portal/admin/orders/{id}/resend-signing-link` (name: `admin_order_resend_signing_link`)
- **Guard:** Order must have a non-null `signingToken` and not be signed yet (`signedAt === null`).
- **Action:** Dispatch `AdminOnboardingInitiated` event on the event bus with the existing `Order.signingToken`.
- **Flash:** "Odkaz k podpisu byl znovu odeslán."
- **Error flash** (if guard fails): "Odkaz k podpisu nelze odeslat — objednávka nemá aktivní podpisový token."

#### 6b. Onboarding payment reminder

**New file:** `src/Controller/Admin/AdminOrderSendOnboardingReminderController.php`

- **Route:** `POST /portal/admin/orders/{id}/send-onboarding-reminder` (name: `admin_order_send_onboarding_reminder`)
- **Guard:** Order must be admin-created (`createdByAdmin !== null`), signed (`signedAt !== null`), and not yet paid (`paidAt === null`).
- **Action:** Dispatch `OnboardingPaymentReminderRequested` with `stage = 'manual'` on the event bus. The handler already has a `default` match arm that logs and returns for unknown stages, so add `'manual'` as a recognized stage in `SendOnboardingPaymentReminderEmailHandler` with subject "Připomínka: dokončete platbu objednávky — Fajnesklady.cz" (same as D+2).
- **Flash:** "Připomínka platby byla odeslána."

#### 6c. Manual billing payment request

**New file:** `src/Controller/Admin/AdminOrderSendBillingReminderController.php`

- **Route:** `POST /portal/admin/orders/{id}/send-billing-reminder` (name: `admin_order_send_billing_reminder`)
- **Guard:** Order must have a contract with `billingMode = MANUAL_RECURRING`. Must have a pending `ManualPaymentRequest` for the current cycle (via `ManualPaymentRequestRepository::findPendingForCurrentCycle()`).
- **Action:** Dispatch `ManualBillingPaymentRequested` event with the current `ManualPaymentRequest.id` and `stage = 'manual'`. Add `'manual'` as a recognized stage in `SendManualBillingPaymentRequestedEmailHandler` — uses subject "Platba je nyní splatná — Fajnesklady.cz" and template `email/manual_billing_payment_due_today.html.twig` (the most neutral of the three).
- **Flash:** "Výzva k platbě byla odeslána."
- **Error flash:** "Nelze odeslat — neexistuje čekající platební požadavek pro aktuální období."

#### 6d. Fine payment reminder

**New file:** `src/Controller/Admin/AdminOrderSendFineReminderController.php`

- **Route:** `POST /portal/admin/orders/{id}/send-fine-reminder/{fineId}` (name: `admin_order_send_fine_reminder`)
- **Guard:** Fine must belong to the order's contract. Fine must be payable (`$fine->isPayable()`).
- **Action:** Dispatch `FinePaymentReminderRequested` with `stage = 0` (generic reminder, same template as D+7). The handler already handles this.
- **Flash:** "Připomínka pokuty byla odeslána."
- **Error flash:** "Pokuta není ve stavu, kdy lze odeslat připomínku."

### 7. Manual action buttons on admin order detail template

**File:** `templates/admin/order/detail.html.twig`

Add a new "Manuální akce" card section (blue left border, matching the advance-notice style) before the email history section. Each action is a small form with a POST button + CSRF token. Buttons are conditionally rendered based on guards:

```twig
{# Resend signing link — only when token exists and not signed #}
{% if order.signingToken is not null and order.signedAt is null %}
    <form method="post" action="{{ path('admin_order_resend_signing_link', {id: order.id}) }}">
        <input type="hidden" name="_token" value="{{ csrf_token('resend_signing_link') }}">
        <button type="submit" class="...">Znovu odeslat odkaz k podpisu</button>
    </form>
{% endif %}

{# Onboarding payment reminder #}
{% if order.createdByAdmin is not null and order.signedAt is not null and order.paidAt is null %}
    ...Odeslat připomínku platby (onboarding)...
{% endif %}

{# Manual billing request — only MANUAL_RECURRING with contract #}
{% if contract is not null and contract.billingMode.value == 'manual_recurring' %}
    ...Odeslat výzvu k platbě...
{% endif %}

{# Fine reminders — one button per unpaid fine #}
{% for fine in fines if fine.payable %}
    ...Odeslat připomínku pokuty: {{ fine.type.label }}...
{% endfor %}
```

If no actions are available, don't render the section at all.

### 8. Add `orderId` filter to existing email log page (bonus)

**File:** `src/Repository/EmailLogFilter.php`

Add optional `?Uuid $orderId = null` property.

**File:** `src/Repository/EmailLogRepository.php`

In `findPaginated()` and `countWithFilter()`, add a `WHERE` clause for `orderId` when the filter has it set.

**File:** `src/Controller/Admin/AdminEmailLogController.php`

Read optional `orderId` query parameter; if present, pass it to the filter. This lets the email history table link to "Zobrazit vše" → `/portal/admin/email-log?orderId=...` at the bottom of the inline table if there are more than 50 emails.

## Acceptance

- [ ] `EmailLog` entity has a new nullable `orderId` column (UUID, indexed). Migration generated.
- [ ] `EmailLogger` reads `X-Order-Id` header from email and persists it as `orderId`.
- [ ] All 28 order-related email handlers (listed in Context) set the `X-Order-Id` header.
- [ ] Admin order detail shows "E-mailová komunikace" section with inline table of emails for that order.
- [ ] Template names display as human-readable Czech labels.
- [ ] Each row links to the existing email log detail page.
- [ ] "Manuální akce" section appears with contextually-appropriate buttons.
- [ ] Resend signing link: works for unsigned orders with a token; sends the signing link email.
- [ ] Onboarding payment reminder: works for signed-but-unpaid admin-onboarded orders.
- [ ] Manual billing payment request: works for MANUAL_RECURRING contracts with pending payment request.
- [ ] Fine payment reminder: works for each payable fine on the order's contract.
- [ ] All four manual actions create an `EmailLog` entry with the order's ID (visible in the history table immediately after sending).
- [ ] Each action has CSRF protection and proper flash messages.
- [ ] Existing email log page supports optional `orderId` filter parameter.
- [ ] `composer quality` is green.

## Out of scope

- **Landlord order detail** — admin-only for now. Landlords don't manage billing emails and the email log may contain payment links.
- **Backfill existing EmailLog rows** — impractical; only future emails get linked. The UI notes this.
- **Email preview/resend of arbitrary emails** — this spec only covers the four specific manual actions. A generic "resend this exact email" from the log is a separate feature.
- **Handover emails** — the handover protocol has its own lifecycle; linking handover emails to the order is possible but adds complexity for minimal value.
- **Non-order emails** (welcome, verification, place access) — these don't belong on the order detail.

## Open questions

None — proceed.
