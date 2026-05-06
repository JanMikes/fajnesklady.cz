# 019 — Settle outstanding usage when customer cancels an open-ended recurring payment

**Status:** draft (needs product confirmation on confirmation-step UX before promotion to `ready`)
**Type:** feature / billing correctness
**Scope:** small (~5 files + tests)
**Depends on:** none — extends the existing `CancelRecurringPaymentHandler`

## Problem

For open-ended (doba neurčitá) contracts, each successful monthly charge advances `paidThroughDate` by one calendar month. Between billing cycles the customer is in the middle of a paid period — they have already paid for service through `paidThroughDate`.

But what about days **after** `paidThroughDate` and **before** the next scheduled charge? Today, two situations:

1. **Customer-initiated cancel** via `/opakovana-platba/{contractId}/zrusit` (`CancelRecurringPaymentController`): we call `voidRecurrence` on the GoPay token and call `Contract::cancelRecurringPayment()` — that clears `goPayParentPaymentId` and `nextBillingDate`. **No final settlement charge is attempted.** If `paidThroughDate < now`, those used-but-unpaid days are silently written off.
2. **Forced termination after 3 failed charges** (`RetryFailedPaymentsCommand:91-114`): we already call `ContractService::calculateOutstandingDebt`, persist it via `Contract::setOutstandingDebt()`, and emit `ContractTerminatedDueToPaymentFailure`. The amount is **tracked** for collections but no automated GoPay charge is made (the customer's card has just been declining; trying again would only fail again).

Today's miss is path (1): a paying customer in good standing who decides to cancel mid-period gets free use of the days between `paidThroughDate` and "now", because we never attempt a settlement charge using the still-valid token before voiding it.

## Goal

When a customer cancels an open-ended recurring payment via the email/portal cancel link, **and** there is a non-zero outstanding amount (`now > paidThroughDate`), we:

1. Show them the prorated amount up-front on the confirmation page (a real number, not a vague "you may owe").
2. After they confirm, charge that amount via the existing GoPay token (`createRecurrence` for the prorated balance), **then** void the token, **then** mark the contract recurring-cancelled.
3. If the settlement charge succeeds → contract closed cleanly, customer gets a "vyúčtování" e-mail.
4. If the settlement charge fails → fall through to the existing outstanding-debt path: void the token anyway, record the outstanding amount on the contract, alert admins. We do **not** block the cancel on a payment failure — the customer asked to cancel and we honour that.

For fixed-end recurring contracts the same logic doesn't apply (the schedule is already deterministic and the cron handles the prorated tail), but for safety the same flow runs — if there's no outstanding debt (`paidThroughDate >= now`) it's a zero-charge cancel, indistinguishable from today's behaviour.

## Context (current state)

- **Cancel link generator:** `src/Service/RecurringPaymentCancelUrlGenerator.php` — produces signed URLs sent in `recurring_payment_established.html.twig` and `recurring_payment_advance_notice.html.twig`.
- **Cancel controller:** `src/Controller/Public/CancelRecurringPaymentController.php` — already a single-action controller. Today it's a one-step POST: confirm → cancel done. We need to add a "preview the settlement amount, then confirm" intermediate state without breaking the existing GET → POST → success flow.
- **Cancel handler:** `src/Command/CancelRecurringPaymentHandler.php` — calls `goPayClient->voidRecurrence($parentPaymentId)` then `contract->cancelRecurringPayment()` then dispatches `RecurringPaymentCancelled`. Atomic by virtue of `doctrine_transaction` middleware.
- **Outstanding-debt math:** `src/Service/ContractService.php:90` `calculateOutstandingDebt(Contract, terminationDate)` — already handles the "days between paidThroughDate and termination" case. Returns 0 when `paidThroughDate >= terminationDate`. Reuse as-is.
- **GoPay charge:** `src/Service/GoPay/GoPayApiClient.php:55` `createRecurrence($parentPaymentId, $amount, $orderNumber, $description)`. Same call the cron uses; safe to invoke for an arbitrary settlement amount as long as it's ≤ 15 000 Kč (Podmínky čl. III). For doba neurčitá the prorated month-fragment is always under one month's rate, which is way under the ceiling — assert on it anyway and surface a clear error if violated.
- **Existing termination path:** `src/Console/RetryFailedPaymentsCommand.php` already shows the *recording*-only path (`Contract::setOutstandingDebt`, `ContractTerminatedDueToPaymentFailure`). Reuse the same persistence + event for the failure branch.
- **Cancel template:** `templates/public/cancel_recurring_payment.html.twig` — currently a single page that switches on `alreadyCancelled` / `success` / `error`. Add a fourth state for "preview before confirm" with the settlement amount visible.
- **Compliance:** `.claude/COMPLIANCE.md` (Billing modes section) lists this gap explicitly. The new handler closes it.

## Architecture (target)

```
[Customer clicks signed cancel link in email]
                │
                ▼
GET /opakovana-platba/{id}/zrusit
  → render template with `outstandingAmount = ContractService::calculateOutstandingDebt(contract, now)`
  → "Po potvrzení zaplatíte X Kč za nevyúčtované dny od {paidThroughDate}, poté pravidelná platba skončí."
                │
                ▼
[Customer clicks "Potvrdit a zrušit"]
                │
                ▼
POST /opakovana-platba/{id}/zrusit
  → SettleAndCancelRecurringPaymentHandler:
      1. Compute outstanding (re-compute, do NOT trust the rendered amount).
      2. If outstanding > 0:
         a. createRecurrence($parentPaymentId, outstanding, …)
         b. Poll for confirmation (mirror ChargeRecurringPaymentHandler logic).
         c. On success → Contract::recordBillingCharge(now, null, now)
                        → dispatch RecurringPaymentCharged (final-settlement variant)
         d. On failure → Contract::setOutstandingDebt(outstanding)
                        → dispatch ContractTerminatedDueToPaymentFailure
                        → continue to step 3 (still cancel the token!)
      3. voidRecurrence($parentPaymentId)
      4. Contract::cancelRecurringPayment()
      5. Dispatch RecurringPaymentCancelled (always, even if settle failed)
```

The settle-then-cancel ordering matters: void the token *after* the settle attempt, otherwise we lose the only means to charge it.

## Requirements

### 1. Compute the settlement preview (controller-side)

`src/Controller/Public/CancelRecurringPaymentController.php` — inject `ContractService` and the clock; compute `outstandingAmount` for both GET and POST paths and pass it into the template / handler.

### 2. New handler: `SettleAndCancelRecurringPaymentHandler`

Replace the body of the existing `CancelRecurringPaymentHandler` (or add a new command — recommended new command `SettleAndCancelRecurringPaymentCommand` so `CancelRecurringPaymentCommand` keeps its current "void only" semantic for the admin-side panic button). The new handler:

```php
public function __invoke(SettleAndCancelRecurringPaymentCommand $command): SettleAndCancelResult
{
    $contract = $command->contract;
    if (!$contract->hasActiveRecurringPayment()) {
        return SettleAndCancelResult::alreadyCancelled();
    }

    $now = $this->clock->now();
    $outstandingAmount = $this->contractService->calculateOutstandingDebt($contract, $now);

    $settled = false;
    if ($outstandingAmount > 0) {
        try {
            $this->guardSingleChargeCeiling($outstandingAmount);
            $payment = $this->goPayClient->createRecurrence(
                $contract->goPayParentPaymentId,
                $outstandingAmount,
                $this->buildOrderNumber($contract->id, $now, 'CANCEL'),
                sprintf('Vyúčtování při zrušení - %s (%s)', $contract->storage->storageType->name, $now->format('m/Y')),
            );
            // Poll for confirmation — mirror ChargeRecurringPaymentHandler.
            // On confirmed PAID:
            $contract->recordBillingCharge($now, null, $now);
            $settled = true;
            $this->eventBus->dispatch(new RecurringPaymentCharged(...));
        } catch (\Throwable $e) {
            $contract->setOutstandingDebt($outstandingAmount);
            $this->eventBus->dispatch(new ContractTerminatedDueToPaymentFailure(...));
        }
    }

    // Always void + clear, regardless of settle outcome
    try {
        $this->goPayClient->voidRecurrence($contract->goPayParentPaymentId);
    } catch (\Throwable $e) {
        // Already-void tokens may error — log but don't propagate
        $this->logger->info(...);
    }
    $contract->cancelRecurringPayment();
    $this->eventBus->dispatch(new RecurringPaymentCancelled(...));

    return SettleAndCancelResult::completed(settled: $settled, settledAmount: $outstandingAmount);
}
```

`SettleAndCancelResult` is a small readonly value object so the controller can render the right end-state template (settled OK vs. settled-failed-but-cancelled vs. nothing-was-owed).

### 3. Update `CancelRecurringPaymentController` UX

Three states to render:

- **GET, outstanding == 0**: as today, show "Pravidelná platba bude zrušena, k zaplacení nic nezbývá" + confirm button.
- **GET, outstanding > 0**: NEW state, show *"Při zrušení vám bude účtováno X,XX Kč za období od {paidThroughDate} do dnes — {N} dní × {dailyRate} Kč."* + confirm button. Make the amount unmissable (large, accented).
- **POST result**: dispatch `SettleAndCancelRecurringPaymentCommand`, render success template that branches on the result (settled / settled-failed-tracked-as-debt / nothing-owed).

Use the same template (`templates/public/cancel_recurring_payment.html.twig`) extended with new branches; no need for a multi-page wizard.

### 4. Customer e-mails

- `templates/email/recurring_payment_settled_on_cancel.html.twig` (new) — sent when settle succeeded, lists the prorated amount, the period covered, the contract's effective end date.
- Reuse `recurring_payment_cancelled.html.twig` for the "nothing owed" case.
- For settle-failed-but-cancelled, reuse the existing payment-default e-mail (`SendPaymentDefaultEmailHandler` already handles `ContractTerminatedDueToPaymentFailure`) — the wording is already correct ("dluh evidujeme, kontaktujte nás").

### 5. Admin signal

Existing `RecurringPaymentCancelled` admin handler is fine for the happy path. The settle-failed branch already routes through `ContractTerminatedDueToPaymentFailure` which has its own admin notification path.

### 6. Tests

- `tests/Integration/Command/SettleAndCancelRecurringPaymentHandlerTest.php` — three scenarios:
  - `paidThroughDate >= now` → no settle attempt, void called, contract cancelled.
  - `paidThroughDate < now` and settle succeeds → 1 × `createRecurrence` for prorated amount, contract cancelled, `RecurringPaymentCharged` dispatched.
  - `paidThroughDate < now` and settle fails → outstanding debt persisted, `ContractTerminatedDueToPaymentFailure` dispatched, **and** void still called, contract still cancelled.
- `tests/Integration/Controller/Public/CancelRecurringPaymentControllerTest.php` — extend with the new GET preview state + the three POST result branches.
- Unit-test the 15 000 Kč ceiling guard so a misconfigured contract can't accidentally try to charge an absurd settlement.

## Acceptance

- [ ] When a customer cancels an unlimited contract with `paidThroughDate < now`, GoPay charges the prorated outstanding amount before the token is voided.
- [ ] When a customer cancels with `paidThroughDate >= now`, behaviour is identical to today (no charge, void + cancel).
- [ ] If the settlement charge fails (e.g., card already cancelled in GoPay portal — see spec 018 interaction), the contract is **still** cancelled and the void still attempted, but the outstanding amount is recorded via `Contract::setOutstandingDebt` and `ContractTerminatedDueToPaymentFailure` is dispatched so admins know a manual collection step is needed.
- [ ] The cancel page shows the prorated amount **before** the customer confirms, with a breakdown (days × daily rate). No "surprise" final charge.
- [ ] `composer quality` is green.
- [ ] Manual end-to-end test in sandbox: cancel an open-ended contract whose last charge was > 1 day ago; confirm card statement shows the prorated charge and the customer e-mail itemises it.

## Out of scope

- **Refund flow.** If `paidThroughDate > now` (customer paid for a period they aren't using), today they don't get a refund. Per Podmínky čl. VI that's intentional. Do **not** add a refund here.
- **Self-serve cancel for fixed-end contracts.** Fixed-end already has a deterministic schedule; the customer doesn't need a cancel link mid-contract. The portal flow can stay limited to open-ended contracts (the link generator only fires from `RecurringPaymentEstablished` for open-ended today).
- **Bulk-settle script for already-cancelled contracts that have outstanding debt.** If we discover a backlog when this ships, write a separate one-off `bin/console` data-fix.
- **Invoicing for the settlement charge.** Invoicing is downstream of `RecurringPaymentCharged` (existing flow); this spec doesn't change that pipeline.

## Open questions

1. **Confirmation copy.** Does the cancel page's "Při zrušení vám bude účtováno X,XX Kč" wording need legal review (Podmínky / VOP)? Recommend showing it to the lawyer alongside spec 016's open items in the next batch — not a blocker for sandbox testing.
2. **Failed-settle e-mail tone.** Reusing `SendPaymentDefaultEmailHandler` is convenient but the customer's mental model is different — they *intentionally* cancelled. Worth a small wording variant? Defer until production frequency tells us if it matters.
