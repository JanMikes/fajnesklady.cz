# 018 — Handle GoPay-side cancellation of recurring (ON_DEMAND) payments

**Status:** draft (needs sandbox verification before promotion to `ready`)
**Type:** feature / payment robustness
**Scope:** small (~6–8 files)
**Depends on:** none — extends the existing recurring-payment lifecycle

## Problem

The GoPay payment portal (the customer-facing screen the customer can revisit using the link from `RecurringPaymentEstablished` / advance-notice e-mails) exposes a button **"Ukončit opakovanou platbu"**. We don't currently know — and haven't tested — what happens when the customer clicks it.

Best current understanding (unverified):

- GoPay revokes / voids the parent (ON_DEMAND) payment token. Our stored `Contract::$goPayParentPaymentId` becomes a dead reference.
- Our nightly `app:process-recurring-payments` cron (`src/Console/ProcessRecurringPaymentsCommand.php`) calls `ChargeRecurringPaymentHandler` → `GoPayApiClient::createRecurrence()` → `Payments::createRecurrence($parentPaymentId, …)`. That call presumably fails with a GoPay-specific error. We catch it as a generic `GoPayException` (`src/Service/GoPay/GoPayApiClient.php:132`) and dispatch the standard `RecurringPaymentFailed` event with `reason = $e->getMessage()`.
- That event currently fans out to **two** handlers:
  - `SendRecurringPaymentFailedEmailHandler` → customer e-mail "Platba za pronájem se nepodařila" (`templates/email/recurring_payment_failed.html.twig`). The wording assumes a transient failure (insufficient funds / expired card).
  - `SendRecurringPaymentFailedAdminEmailHandler` → admin e-mail "UPOZORNĚNÍ: Neúspěšná platba". Same assumption.
- The retry cron (`src/Console/RetryFailedPaymentsCommand.php`) tries again on day 2 and day 3. After the third failure it calls `CancelRecurringPaymentCommand` (which calls `voidRecurrence` on the now-already-dead token — likely also fails), terminates the contract for `PAYMENT_FAILURE`, and emits `ContractTerminatedDueToPaymentFailure`.

So today: a customer who deliberately cancels in the GoPay portal receives **three "platba se nepodařila" e-mails over three days** that imply they should "fix their card", and admins get the same misleading signal. After day 3 the contract is force-terminated under a payment-default reason that doesn't reflect reality.

This is a **clarity / dignity / compliance** problem more than an outage. Money isn't lost. But the customer message is wrong, the admin signal is wrong, and the contract reason is wrong.

## Goal

When a customer cancels the recurring payment **on GoPay's side**, our system:

1. Detects this distinct cause (vs. generic billing failure) — synchronously on the next charge attempt and, if GoPay sends one, asynchronously via webhook.
2. Stops retrying immediately (clear `goPayParentPaymentId`, `nextBillingDate`).
3. Sends the customer an **acknowledgement** e-mail: "Zrušili jste pravidelnou platbu — co dál?" with manual-payment instructions and the `simek@fajnesklady.cz` contact. Not three "platba se nepodařila" alarms.
4. Sends admins a **distinguishable** alert: "Zákazník zrušil opakovanou platbu v bráně GoPay — kontakt: …" so the operator can decide what to do.
5. Leaves the contract in a documented holding state (see open question 1 — until resolved, default to "pause billing, do not auto-terminate; admin acts manually").

## Context (current state)

### Recurring lifecycle today

- **Setup:** `OrderAcceptController` → `InitiatePaymentHandler::__invoke()` (`src/Command/InitiatePaymentHandler.php:34`) decides `needsRecurring` via `PriceCalculator::needsRecurringBilling()`. If true, calls `GoPayApiClient::createRecurringPayment()` (`src/Service/GoPay/GoPayApiClient.php:36`) which adds `recurrence_cycle = ON_DEMAND, recurrence_date_to = '2099-12-31'` to the GoPay create-payment payload.
- **First success → contract becomes recurring:** `ProcessPaymentNotificationHandler::__invoke()` (`src/Command/ProcessPaymentNotificationHandler.php:46`) calls `Order::setGoPayParentPaymentId()` and dispatches `RecurringPaymentEstablished`. Contract is created from order in `OrderService::confirmPayment()`; it inherits the parent payment ID via `Contract::setRecurringPayment()` (`src/Entity/Contract.php:157`).
- **Periodic charge:** cron `app:process-recurring-payments` (`src/Console/ProcessRecurringPaymentsCommand.php`) finds contracts due via `ContractRepository::findDueForBilling()`, dispatches `ChargeRecurringPaymentCommand`. Handler (`src/Command/ChargeRecurringPaymentHandler.php:60`) calls `goPayClient->createRecurrence($parentPaymentId, …)`.
- **Failure path:** any `GoPayException | PaymentNotConfirmedException` is caught **outside** the doctrine_transaction (in the console command itself, `ProcessRecurringPaymentsCommand.php:64-83` and `RetryFailedPaymentsCommand.php:72-89`), then `Contract::recordFailedBillingAttempt()` is called and `RecurringPaymentFailed` is dispatched with `reason = $e->getMessage()`.
- **3-strike termination:** in `RetryFailedPaymentsCommand.php:91-114` after 3 failed attempts, the contract is auto-terminated for `TerminationReason::PAYMENT_FAILURE` and `ContractTerminatedDueToPaymentFailure` is dispatched.

### Existing handlers / templates we'll reuse

- `src/Event/RecurringPaymentFailed.php` — readonly event DTO `(contractId, attempt, reason, occurredOn)`.
- `src/Event/SendRecurringPaymentFailedEmailHandler.php` — customer e-mail.
- `src/Event/SendRecurringPaymentFailedAdminEmailHandler.php` — admin e-mail.
- `templates/email/recurring_payment_failed.html.twig` and `templates/email/recurring_payment_failed_admin.html.twig` — Twig templates with `attempt`, `reason`, `cancelUrl` etc.
- `src/Command/CancelRecurringPaymentHandler.php` (`src/Command/CancelRecurringPaymentHandler.php:23`) — already exists; calls `goPayClient->voidRecurrence()` then `contract->cancelRecurringPayment()` (entity method clears `goPayParentPaymentId` and `nextBillingDate`, see `src/Entity/Contract.php:179-183`). Reusable for the contract-side cleanup, but we need a variant that **skips** the GoPay `voidRecurrence` call (the token is already void on GoPay's side — calling void on a void token will likely error; verify in sandbox).
- `src/Service/GoPay/GoPayApiClient.php:129` — `assertSuccess()` throws `GoPayException` with `$response->statusCode` and the JSON body. The body includes GoPay's `error_code` field which is the only reliable signal for distinguishing "user cancelled" from other errors. Today we lose it because we serialise to a string immediately.
- `src/Service/GoPay/GoPayException.php` — currently a plain exception. Will need to be extended (or a subclass introduced) to carry the structured `error_code`.
- `src/Controller/Public/PaymentNotificationController.php` (route `/webhook/gopay`) → `ProcessPaymentNotificationCommand` → `ProcessPaymentNotificationHandler`. Currently only acts on `isPaid()` / `isCanceled()` order states and `isPaid()` recurring reconciliations (`ProcessPaymentNotificationHandler.php:69, 83`). Does **not** handle webhooks for parent-payment voids.

### GoPay error vocabulary — what we need to find out (verification step)

The `assertSuccess()` exception body has the form `{ "errors": [{ "error_code": NNN, "scope": "…", "field": "…", "message": "…" }] }`. We need the actual `error_code` GoPay returns when calling `createRecurrence` against a parent token that the customer has cancelled. Likely candidates from GoPay docs:

- `5018` — payment cancelled
- `5052` — recurrence cancelled
- `5053` — recurrence cycle finished

Don't trust any of those without sandbox verification — the docs are vague and the codes drift.

## Architecture (target)

```
[Customer clicks "Ukončit opakovanou platbu" in GoPay portal]
                      │
        ┌─────────────┴─────────────┐
        │                           │
        ▼                           ▼
[GoPay sends webhook?]      [Next createRecurrence
 (verify in sandbox)         attempt fails]
        │                           │
        ▼                           ▼
ProcessPaymentNotification   ChargeRecurringPaymentHandler
Handler (extended to              (catches GoPayException,
recognise parent-void              checks error_code,
notification)                      branches if it's "cancelled")
        │                           │
        └─────────────┬─────────────┘
                      ▼
        Dispatch RecurringPaymentCancelledByCustomer
              (new event, NOT RecurringPaymentFailed)
                      │
       ┌──────────────┼──────────────┐
       ▼              ▼              ▼
 Customer e-mail  Admin e-mail  Contract.cancelRecurringPayment()
 (acknowledgement)(distinct      (clears parent ID + nextBillingDate;
                   subject)       does NOT terminate)
```

## Requirements

### 1. Verification phase (must complete before code lands)

Before writing any production code, run an end-to-end sandbox reproduction:

1. In a dev/sandbox env with the GoPay sandbox merchant configured, create a recurring order and complete the first payment (token is established).
2. Open the GoPay customer portal link (the one we send in `recurring_payment_established.html.twig`) and click **"Ukončit opakovanou platbu"**.
3. From a PHP shell or a one-shot script, call `GoPayApiClient::createRecurrence($parentPaymentId, 100, 'TEST', 'TEST')`. Capture the **complete** error response — HTTP status, GoPay JSON body, all `error_code` values.
4. Tail `var/log/dev.log` and any GoPay webhook arrivals at `/webhook/gopay`. Note whether a notification arrives at the moment of cancellation (vs. only after the failed charge attempt).
5. Document findings in this spec (replace this section with the actual values) and **only then** promote status to `ready`.

The implementation that follows this section is **conditional** on what the verification finds. The code sketches below assume the most likely GoPay behaviour (synchronous error on `createRecurrence`, no proactive webhook); adjust if reality differs.

### 2. Structured GoPay error — surface `error_code` to callers

`src/Service/GoPay/GoPayException.php` (or new `RecurrenceVoidedByCustomerException` extending `GoPayException`):

The current `assertSuccess()` swallows the GoPay error structure into a string. Refactor so the exception keeps the parsed body:

```php
final class GoPayException extends \RuntimeException
{
    /** @param list<array{error_code:int, scope?:string, field?:string, message?:string}> $errors */
    public function __construct(
        string $message,
        int $statusCode,
        public readonly array $errors = [],
    ) {
        parent::__construct($message, $statusCode);
    }

    public function hasErrorCode(int $code): bool
    {
        foreach ($this->errors as $err) {
            if ($code === ($err['error_code'] ?? null)) {
                return true;
            }
        }
        return false;
    }
}
```

Update `GoPayApiClient::assertSuccess()` (`src/Service/GoPay/GoPayApiClient.php:129`) to extract `$response->json['errors']` (default `[]`) and pass them to the constructor.

### 3. New event: `RecurringPaymentCancelledByCustomer`

`src/Event/RecurringPaymentCancelledByCustomer.php` — readonly DTO. Mirror `RecurringPaymentFailed`'s shape:

```php
final readonly class RecurringPaymentCancelledByCustomer
{
    public function __construct(
        public Uuid $contractId,
        public string $detectionSource,    // 'charge_attempt' | 'webhook'
        public ?string $goPayErrorCode,    // string for forward-compat
        public \DateTimeImmutable $occurredOn,
    ) {}
}
```

This is **separate** from `RecurringPaymentCancelled` (which today fires from our admin/customer-initiated `CancelRecurringPaymentCommand`) so handlers can distinguish "we cancelled it" from "GoPay told us the customer cancelled it" without inspecting message context.

### 4. Branch in `ChargeRecurringPaymentHandler`

`src/Command/ChargeRecurringPaymentHandler.php:105` — extend the `GoPayException` catch:

```php
} catch (GoPayException $e) {
    if ($this->isCustomerCancellationError($e)) {
        // Don't propagate as billing failure — handled separately
        $contract->cancelRecurringPayment();   // clears goPayParentPaymentId + nextBillingDate

        $this->eventBus->dispatch(new RecurringPaymentCancelledByCustomer(
            contractId: $contract->id,
            detectionSource: 'charge_attempt',
            goPayErrorCode: $this->extractErrorCode($e),
            occurredOn: $now,
        ));

        return; // do NOT re-throw — the cron must not record a failed billing attempt
    }
    throw $e;
} catch (PaymentNotConfirmedException $e) {
    throw $e;
}
```

`isCustomerCancellationError(GoPayException $e): bool` matches the verified `error_code` from the verification phase. Hardcode the integer with a class-level `private const RECURRENCE_CANCELLED_ERROR_CODES = [...]`.

**Critical:** `Contract::cancelRecurringPayment()` mutates the entity, but this catch is inside the doctrine_transaction middleware. Since we're returning normally (no throw), the transaction commits the mutation. Good — no flush plumbing needed. But the calling cron (`ProcessRecurringPaymentsCommand.php:64`) currently treats absence-of-throw as "successful charge". That's wrong for this branch — the contract was modified but no money moved. Adjust the cron's success counter to not count cancellation-detections as successes, or (cleaner) return a small status enum from the handler. Recommendation: **add a return type** to the handler — `ChargeRecurringPaymentResult` (enum: `CHARGED`, `CANCELLED_BY_CUSTOMER`, `SKIPPED_ZERO_AMOUNT`) — and switch on it in both crons. (Both `ProcessRecurringPaymentsCommand` and `RetryFailedPaymentsCommand` need updating.)

### 5. Branch in `ProcessPaymentNotificationHandler` (only if verification confirms a webhook fires)

If the verification phase shows GoPay sends an asynchronous notification at the moment of customer cancellation (e.g. with a state like `CANCELED` on the parent payment), extend `ProcessPaymentNotificationHandler::__invoke()` (`src/Command/ProcessPaymentNotificationHandler.php:38`):

```php
// After the existing $order branch and existingPayment idempotency check:
if (
    null !== $status->parentId
    && '' !== $status->parentId
    && $status->isCanceled()      // or whatever state GoPay uses
) {
    $contract = $this->contractRepository->findByGoPayParentPaymentId($status->parentId);
    if (null !== $contract && $contract->hasActiveRecurringPayment()) {
        $contract->cancelRecurringPayment();
        $this->eventBus->dispatch(new RecurringPaymentCancelledByCustomer(
            contractId: $contract->id,
            detectionSource: 'webhook',
            goPayErrorCode: null,
            occurredOn: $now,
        ));
    }
    return;
}
```

If verification shows **no** webhook fires for customer cancellation, skip this entire requirement and document that finding in the spec.

### 6. Customer e-mail handler

`src/Event/SendRecurringPaymentCancelledByCustomerEmailHandler.php` — new file, mirrors `SendRecurringPaymentFailedEmailHandler` shape (constructor injects `ContractRepository`, `MailerInterface`, `LoggerInterface`).

Subject: `Pravidelná platba byla ukončena - Fajnesklady.cz`

Template: `templates/email/recurring_payment_cancelled_by_customer.html.twig`. Wording must:

- Acknowledge the customer's action (not blame): *"Zaznamenali jsme, že jste v platební bráně GoPay zrušili pravidelnou měsíční platbu pro pronájem skladu č. {storage} v pobočce {place}."*
- Explain consequence: *"Vaše smlouva o nájmu zůstává v platnosti až do dohodnutého data ukončení ({contract.endDate})."* (or "do dobu neurčitou — bez automatických plateb už nebudeme strhávat" for unlimited).
- State next steps: pay the next installment manually (link to a future "pay outstanding balance" route — see open question 3; for now link to the contract detail in portal), or contact us to terminate the rental early.
- Provide cancellation/changes contact: `simek@fajnesklady.cz` (per `.claude/COMPLIANCE.md` Recurring payments / Podmínky čl. VI).
- Link to `Podmínky opakovaných plateb` page.

### 7. Admin e-mail handler

`src/Event/SendRecurringPaymentCancelledByCustomerAdminEmailHandler.php` — new file, mirrors `SendRecurringPaymentFailedAdminEmailHandler` shape, but:

Subject: `OZNÁMENÍ: Zákazník zrušil opakovanou platbu - {customer.fullName}` (note: **OZNÁMENÍ** not **UPOZORNĚNÍ** — this isn't an alert, it's information. The distinction is intentional so admins can filter.)

Template: `templates/email/recurring_payment_cancelled_by_customer_admin.html.twig`. Body includes:

- Customer name + e-mail + phone (from `Contract::$user`).
- Contract details (place, storage type, storage number, start/end dates, monthly amount, paid-through date).
- Whether outstanding debt exists (call `ContractService::calculateOutstandingDebt($contract, $now)` — `src/Service/ContractService.php:90`).
- Recommended action: contact customer within 7 working days (per Podmínky čl. V's notification cadence, even though the cancellation was customer-initiated).
- Detection source (`charge_attempt` vs `webhook`) — useful debug signal.

### 8. Suppress the legacy "platba se nepodařila" e-mails for this case

Because the new branch in `ChargeRecurringPaymentHandler` returns **before** dispatching `RecurringPaymentFailed`, both legacy handlers (`SendRecurringPaymentFailedEmailHandler`, `SendRecurringPaymentFailedAdminEmailHandler`) are naturally bypassed for customer-cancellation events. No code change needed in those handlers. Spec author should explicitly verify this in the unit test for the handler change.

### 9. Tests

- `tests/Unit/Service/GoPay/GoPayExceptionTest.php` — `hasErrorCode()` matches against the structured array; preserves message + status.
- `tests/Integration/Command/ChargeRecurringPaymentHandlerTest.php` — extend with two new cases:
  - `createRecurrence` throws `GoPayException` with the cancellation `error_code` → handler returns `CANCELLED_BY_CUSTOMER`, contract has `goPayParentPaymentId === null` and `nextBillingDate === null`, `RecurringPaymentCancelledByCustomer` event was dispatched, **no** `RecurringPaymentFailed` event was dispatched, **no** `Contract::recordFailedBillingAttempt` called.
  - `createRecurrence` throws `GoPayException` with any other `error_code` → existing failure path still works (event dispatched, attempt counter incremented).
- `tests/Integration/Console/ProcessRecurringPaymentsCommandTest.php` and `RetryFailedPaymentsCommandTest.php` — assert that a contract returned `CANCELLED_BY_CUSTOMER` is **not** counted as a failure (no retry scheduled, no terminate-after-3-strikes path).
- E-mail handler unit tests — assert subject, recipient, template, and that the right context keys are present. Pattern: copy `tests/Unit/Event/SendRecurringPaymentFailedEmailHandlerTest.php` if it exists; otherwise mirror an existing e-mail handler test.

### 10. Update `.claude/COMPLIANCE.md`

Add a row under "Where this is enforced in code" pointing at the new event handlers + cron return-value semantics, so the next person touching the recurring lifecycle finds them.

## Acceptance

- [ ] **Verification phase complete and documented in this spec** — actual GoPay error codes recorded, webhook behaviour confirmed (yes/no), HTTP status verified.
- [ ] `GoPayException` carries structured `errors` array; existing call-sites still work (the message/status constructor remains compatible via the third-arg default).
- [ ] `ChargeRecurringPaymentHandler` returns a result enum; both crons (`ProcessRecurringPaymentsCommand`, `RetryFailedPaymentsCommand`) switch on it and treat `CANCELLED_BY_CUSTOMER` distinctly from `CHARGED` and from a thrown failure.
- [ ] When the cancellation `error_code` is returned: contract has cleared `goPayParentPaymentId` + `nextBillingDate`, **no** `failedBillingAttempts++`, **no** retry scheduled.
- [ ] Customer receives one e-mail (`Pravidelná platba byla ukončena`), admins receive one e-mail (`OZNÁMENÍ: Zákazník zrušil opakovanou platbu`), each pointing at the right contact details. **No** "platba se nepodařila" e-mails are sent for this scenario.
- [ ] Contract is **not** auto-terminated for `PAYMENT_FAILURE`. (Per open question 1, default behaviour is "leave open, admin acts manually".)
- [ ] `composer quality` is green (phpstan level 8, code style, full test suite).
- [ ] Manual end-to-end test in sandbox: cancel via GoPay portal → wait for next cron run → confirm both e-mails arrive with correct wording and contract state matches the assertions above.

## Out of scope

- **Refactoring `PriceCalculator`** — handled separately if/when needed; this spec only extends the failure-path branching, not the pricing math.
- **Customer-facing "manage subscription" portal** — we already have `/opakovana-platba/{contractId}/zrusit` (`CancelRecurringPaymentController`) for our own cancel-by-link flow. Building a richer self-serve UI is a separate spec.
- **One-click "pay outstanding balance" CTA** — captured under open question 3; if needed, becomes its own spec since it requires a new payment-creation flow against an already-terminated/paused recurring contract.
- **Backfilling already-cancelled contracts** — if the verification phase reveals existing prod contracts in the broken state, write a one-off `bin/console` data-fix script in a follow-up task. Not part of this spec.
- **Distinguishing "card expired" vs "insufficient funds" vs "issuer declined"** — same generic `RecurringPaymentFailed` channel covers all of those today; finer-grained taxonomy is a future polish item.

## Open questions

1. **Auto-terminate or hold open?** When a customer cancels recurring mid-rental on a fixed-end contract (e.g. cancelled after month 2 of 6), do we (a) keep the contract active until `endDate` and bill the remainder manually, (b) pause billing and require explicit admin action, or (c) auto-terminate immediately (and pursue any outstanding debt the same way the 3-strike path does today)? Spec defaults to **(b) pause + admin acts manually**, since this is the least-irreversible choice and matches the "OZNÁMENÍ not UPOZORNĚNÍ" framing. **Owner/lawyer to confirm.**
2. **Webhook?** Does GoPay send an asynchronous notification at the moment a customer clicks "Ukončit opakovanou platbu" in their portal, or do we only learn at the next failed `createRecurrence` attempt? Verification phase will resolve this; if no webhook, requirement 5 is dropped.
3. **One-click pay-outstanding CTA?** The customer e-mail currently links to the portal contract detail page. Would a "Zaplatit zbývající částku" one-click CTA (creating a one-shot, non-recurring GoPay payment) materially improve recovery? Trade-off: it requires a new endpoint and we'd have to nail down the prorated outstanding amount (which today's `ContractService::calculateOutstandingDebt` already computes). Decide after we see real-world frequency of this scenario in production.
