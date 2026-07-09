# 086 — Admin: mark order externally paid + extend payment deadline

**Status:** done
**Type:** feature
**Scope:** large (~28 files: 1 migration, 2 entity changes, 2 commands + 2 handlers, 2 controllers + 2 forms, 4 cron/repo guards, 1 event, templates, tests)
**Depends on:** 076 (BillingMode::derive), 078 (auto-terminate), 085 (prepaid billing track). Builds directly on the manual-billing machinery (spec 036) and FIO reconciliation (spec 049).

## Problem

Two operational gaps on the admin order detail page (`/portal/admin/orders/{id}`):

1. **No way to record an off-system payment.** A customer pays in cash, or to an old bank account not polled by FIO, and there is no button to reflect that. The manual-track contract keeps its past-due `nextBillingDate`, so `SendManualBillingPaymentRequestsCommand` keeps e-mailing overdue reminders and `TerminateOverdueContractsCommand` will eventually terminate a contract that is actually paid. Today the only "paid outside the system" path is the admin **onboarding** wizard (order creation) — there is nothing for a *running* contract or a *signed order still awaiting its first payment*.

2. **No way to grant a customer more time.** When a customer needs a few extra days, an admin cannot pause dunning + termination. The contract marches through the overdue ladder to termination on schedule. The operator's only levers are "mark paid" (a lie) or "terminate" (the thing they're trying to avoid).

## Goal

Two new buttons in the **"Manuální akce"** panel of `templates/admin/order/detail.html.twig`:

- **"Označit jako externě zaplaceno"** — records that the customer paid off-system (cash / other bank account). Advances the billing period (whole cycle **or** to a specific date), stops the dunning for that cycle, optionally issues a Fakturoid invoice. Hard-gated: the order must be signed by the customer (`order.hasSignature()`). Works for both a running manual-track contract cycle and a signed order still awaiting its first payment.
- **"Prodloužit splatnost"** — extends the payment deadline to an admin-chosen future date. Until that date: no dunning e-mails, no auto-charge/retry, no termination. After it, a fresh due→overdue reminder cadence plays out and termination is measured from the *extended* date. Works on all tracks (manual + AUTO card).

## Context (current state)

### Billing state lives on `Contract`, not `Order`
- `src/Entity/Contract.php` — `nextBillingDate` (L42, the dunning/charge/termination anchor), `paidThroughDate` (L82), `failedBillingAttempts` (L48), `paymentDemandSentAt` (L79).
- `Contract::recordBillingCharge($now, $nextBillingDate, $paidThroughDate)` (L236) — advances the anchor, resets `failedBillingAttempts`/`lastBillingFailedAt`/`pendingRecurringPaymentId`/`paymentDemandSentAt`. **This is the model for a paid cycle.**
- `Contract::markExternallyPrepaid($paidThroughDate)` (L588) — sets `paidThroughDate`, `nextBillingDate = paidThroughDate + 1 day` (null if past `endDate`). Does **not** reset `failedBillingAttempts` or clear `paymentDemandSentAt` — a gap this spec closes with a new method.
- `Contract::usesManualBillingTrack()` (L500), `isFree()` (L577), `getBillingCadenceStep()` (L530, `+1 month`/`+1 year`/`+1 year` for ONE_TIME), `getEffectiveEndDate()` (L417).

### The anchor-drift trap (why "extend" must NOT move `nextBillingDate`)
`ProcessBankTransferPaymentHandler::reconcileBankTransferRecurring` (`src/Command/ProcessBankTransferPaymentHandler.php:92-95`) computes the *next* period as `nextBillingDate->modify(cadenceStep)`. Every future cycle is derived from `nextBillingDate`. Moving it forward to defer a deadline would permanently drift all future billing cycles + corrupt invoice period labels. **Feature 2 therefore introduces an orthogonal `paymentGraceUntil` field and never touches `nextBillingDate`.**

### The four crons that key off `nextBillingDate`
- `src/Console/SendManualBillingPaymentRequestsCommand.php:56-78` — per-contract loop: `ManualBillingReminderSchedule::fromOrder($contract->order)->dueStageOn($now, $contract->nextBillingDate)` → dispatches `DispatchManualBillingNotificationCommand(contractId, periodStart: $contract->nextBillingDate, stage)`.
- `src/Console/TerminateOverdueContractsCommand.php:57-112` — `overdueSince = now − overdueTerminationDays`; candidates from `ContractRepository::findOverdueForTermination($overdueSince)`; re-validates in a locked transaction at L98 (`$dueDate = $contract->nextBillingDate; … $dueDate > $overdueSince`).
- `src/Console/ProcessRecurringPaymentsCommand.php` + `src/Console/RetryFailedPaymentsCommand.php` — AUTO card charge (`Contract::isDueBilling`) + retries (`Contract::needsRetry`).
- Admin overdue digest: `ContractRepository::findWithPaymentIssues($now)` (`src/Repository/ContractRepository.php:602`, WHERE at L615-621) → `OverdueChecker::summarise` → `SendOverdueDigestEmailCommand`.

### Reminder ladder
- `src/Service/Billing/ManualBillingReminderSchedule.php` — `dueStageOn($now, $anchor)` (L87) returns the stage with the greatest offset ≤ today's day-diff from `$anchor` (bracket semantics). Offsets default −7/−2/0/+3/+7, snapshotted per-order on `Order::$manualBillingOffset*`.
- `src/Entity/ManualPaymentRequest.php` — one row per `(contract, periodStart)`, `sentStages` JSON gate (L45), unique constraint (L20). `markPaid` (L100), `hasStageSent`/`recordStageSent`. **New:** `reopenForExtension()` (clears `sentStages`, resets to pending) so a post-grace ladder can replay on the same period row.
- `ManualPaymentRequestRepository::findUnpaidByContractAndPeriod($contract, $periodStart)` — used by reconcile to mark the cycle paid.

### Order signing + first-payment completion
- `src/Entity/Order.php` — `hasSignature()` (L460, `signaturePath !== null && signedAt !== null`) is the legal gate. `canBePaid()` (L293, status CREATED/RESERVED/AWAITING_PAYMENT), `hasAcceptedTerms()` (L217), `setPaymentMethod()` (L495), `setOnboardingBillingTerms(?individualMonthlyAmount, ?paidThroughDate, ?createdByAdmin)` (L612) — the only writer of `Order::$paidThroughDate`.
- `OrderService::confirmPayment($order, $now, ?$explicitAmount)` (`src/Service/OrderService.php:174`) → `markPaid` + audit.
- `OrderService::completeOrder($order, $now): Contract` (L187) — creates the Contract, applies billing mode/frequency, **and carries `order.paidThroughDate` onto the contract via `markExternallyPrepaid`** (L230-232). Guard `canBeCompleted()` = status PAID.
- `CompleteOrderHandler` (`src/Command/CompleteOrderHandler.php`) — dispatched as `CompleteOrderCommand`; owns recurring-date seeding + `contractService->generateDocument` + `signContract`. First-payment completion **must** go through this command, not a raw `completeOrder()` call.
- Nested `CompleteOrderCommand` dispatch from inside a message handler is the established pattern: `ProcessBankTransferPaymentHandler:56`, `ProcessPaymentNotificationHandler:107`.

### Invoicing
- `InvoicingService::issueInvoiceForRecurringPayment(Contract $contract, int $amount, \DateTimeImmutable $chargedAt): Invoice` (`src/Service/InvoicingService.php:169`) — Fakturoid invoice for a recurring cycle, marked paid, best-effort PDF, records `InvoiceCreated` → `SendInvoiceEmailHandler` e-mails it. Use for the running-cycle case.
- `InvoicingService::issueInvoiceForOrder(Order, now)` (L31) — first-payment invoice. `IssueInvoiceForOrderCommand` (`src/Command/IssueInvoiceForOrderCommand.php`) wraps it; dispatch it for the first-payment invoice (it issues regardless of `paymentMethod`; the EXTERNAL-skips-invoice guard lives only in `SendRentalActivatedEmailHandler`, not the service).
- `RecurringAmountCalculator::calculate($contract, $now)` — the current cycle amount (used as the invoice/amount default).
- **Compliance:** amounts are gross (vč. DPH); Fakturoid back-calculates via `from_total_with_vat`. No recurring-consent surface is involved (manual track, no GoPay token) — the strict COMPLIANCE.md consent rules do not apply here.

### Admin action pattern
- Simple email actions (`AdminOrderSendBillingReminderController`) — POST, class `#[IsGranted('ROLE_ADMIN')]`, get order via `OrderRepository::get(Uuid::fromString($id))`, guard state → flash + redirect to `admin_order_detail`, **audit inside the handler / before dispatch** (never after — `doctrine_transaction` flushes inside `dispatch()`; see `.claude/MESSENGER.md` §5).
- Form-page action (`AdminFineCreateController`) — GET renders a form page, POST dispatches a command. Model for the mark-paid form.
- Danger modal (`components/_danger_modal.html.twig`) — password-gated. **Not used here** (these actions are not destructive; a form-page confirm / modal submit is sufficient).
- `Payment` entity (`src/Entity/Payment.php`) — constructor `(Uuid $id, ?Order, ?Contract, Storage, int $amount, \DateTimeImmutable $paidAt, \DateTimeImmutable $createdAt)`. **No payment-method column** — "external" is recorded in the audit log + event, not on the row. `PaymentRepository::save()` exists (mirror `RecordPaymentOnRecurringChargeHandler`).

## Architecture

```
FEATURE 1 — mark externally paid
  Controller (form page) ──dispatch──► RecordExternalPaymentCommand
                                              │
        ┌─────── contract exists? ───────────┤
        │ CASE A (running cycle)             │ CASE B (first payment, no contract)
        │  capture originalNextBillingDate   │  order.setPaymentMethod(EXTERNAL)
        │  contract.recordExternalPayment(   │  if customDate: order paidThroughDate = customDate
        │     paidThroughDate, now)          │  orderService.confirmPayment(order, now, amount)
        │  markPaid the ManualPaymentRequest │  dispatch CompleteOrderCommand(order)   (nested)
        │  persist Payment(EXTERNAL)         │     └─ completeOrder → markExternallyPrepaid(customDate)
        │  if invoice: issueInvoiceForRecurringPayment (best-effort)
        │                                    │  if invoice: dispatch IssueInvoiceForOrderCommand (nested)
        └─ audit 'external_payment_recorded' (inside handler)

FEATURE 2 — extend payment deadline
  Modal ──dispatch──► ExtendPaymentDeadlineCommand
        contract.extendPaymentDeadline(newDate, now)   // sets paymentGraceUntil, NEVER nextBillingDate
        reopen current-period ManualPaymentRequest (clear sentStages)   // fresh post-grace ladder
        audit 'payment_deadline_extended'

  effectiveDunningAnchor() = paymentGraceUntil ?? nextBillingDate    // used by dunning + termination
  isInPaymentGrace(now)    = paymentGraceUntil !== null && now <= paymentGraceUntil
  Cron guards: manual-request skip+re-anchor · terminate COALESCE · recurring+retry skip · digest exclude
  Payment (FIO/card/external) clears paymentGraceUntil.
```

## Requirements

### 1. `Contract` — new `paymentGraceUntil` field + methods

`src/Entity/Contract.php`:

```php
#[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
public private(set) ?\DateTimeImmutable $paymentGraceUntil = null;
```

```php
/**
 * True while an admin-granted payment extension is still in effect: dunning,
 * auto-charge/retry and overdue termination are all suppressed until (and
 * including) $paymentGraceUntil. Cleared by any recorded payment.
 */
public function isInPaymentGrace(\DateTimeImmutable $now): bool
{
    return null !== $this->paymentGraceUntil
        && $now->setTime(0, 0, 0) <= $this->paymentGraceUntil->setTime(0, 0, 0);
}

/**
 * The date the dunning ladder and the termination countdown are measured
 * from. An active or lapsed extension re-anchors both to the extended date
 * (spec 086); otherwise the raw billing anchor is used. Never affects the
 * billing period itself (paidThroughDate / next-period computation).
 */
public function effectiveDunningAnchor(): ?\DateTimeImmutable
{
    return $this->paymentGraceUntil ?? $this->nextBillingDate;
}

/**
 * Extend the payment deadline (spec 086). Sets paymentGraceUntil only —
 * nextBillingDate/paidThroughDate stay put so future cycles never drift.
 */
public function extendPaymentDeadline(\DateTimeImmutable $newDeadline, \DateTimeImmutable $now): void
{
    if ($this->isTerminated()) {
        throw new \DomainException('Cannot extend a terminated contract.');
    }
    if (null === $this->nextBillingDate) {
        throw new \DomainException('Nothing is due — no deadline to extend.');
    }
    $floor = $this->effectiveDunningAnchor();
    if (null !== $floor && $newDeadline->setTime(0, 0, 0) <= $floor->setTime(0, 0, 0)) {
        throw new \DomainException('New deadline must be after the current due date.');
    }
    if ($newDeadline->setTime(0, 0, 0) <= $now->setTime(0, 0, 0)) {
        throw new \DomainException('New deadline must be in the future.');
    }
    $this->paymentGraceUntil = $newDeadline;
}

/**
 * Record an off-system payment (cash / other bank account) covering the
 * rental through $paidThroughDate (spec 086). Advances the anchor like a
 * real charge and clears every dunning flag, including any active grace.
 */
public function recordExternalPayment(\DateTimeImmutable $paidThroughDate, \DateTimeImmutable $now): void
{
    $this->lastBilledAt = $now;
    $this->paidThroughDate = $paidThroughDate;
    $resumesOn = $paidThroughDate->modify('+1 day');
    $this->nextBillingDate = $resumesOn > $this->getEffectiveEndDate() ? null : $resumesOn;
    $this->failedBillingAttempts = 0;
    $this->lastBillingFailedAt = null;
    $this->pendingRecurringPaymentId = null;
    $this->paymentDemandSentAt = null;
    $this->paymentGraceUntil = null;
}
```

Also add `$this->paymentGraceUntil = null;` to **`recordBillingCharge()`** (L236) so a real FIO/card payment during grace clears the extension.

### 2. `ManualPaymentRequest::reopenForExtension()`

`src/Entity/ManualPaymentRequest.php`:

```php
/**
 * Re-open this cycle's request after an admin extends the deadline (spec 086)
 * so the post-grace reminder ladder re-fires on the same period row.
 */
public function reopenForExtension(): void
{
    $this->sentStages = [];
    $this->status = self::STATUS_PENDING;
    $this->paidAt = null;
}
```

### 3. Migration

`docker compose exec web bin/console make:migration` after the entity change. One nullable `payment_grace_until` DATE column on `contract`. **Never hand-write** — generate so `doctrine:schema:validate` passes.

### 4. Feature 2 — `ExtendPaymentDeadlineCommand` + handler

`src/Command/ExtendPaymentDeadlineCommand.php`:
```php
final readonly class ExtendPaymentDeadlineCommand
{
    public function __construct(public Contract $contract, public \DateTimeImmutable $newDeadline) {}
}
```

`src/Command/ExtendPaymentDeadlineHandler.php` (command bus):
1. `$contract->extendPaymentDeadline($command->newDeadline, $now)`.
2. If manual track: `$request = $manualPaymentRequestRepository->findUnpaidByContractAndPeriod($contract, $contract->nextBillingDate); $request?->reopenForExtension();`
3. `auditLogger->log(entityType: 'contract', eventType: 'payment_deadline_extended', payload: ['new_deadline' => …, 'previous_anchor' => …], orderId: $contract->order->id, userIdContext: $contract->user->id)`. Audit **inside** the handler.

Let domain exceptions from `extendPaymentDeadline` propagate; the controller pre-validates (see §6) so they act as a backstop.

### 5. Feature 1 — `RecordExternalPaymentCommand` + handler

`src/Command/RecordExternalPaymentCommand.php`:
```php
final readonly class RecordExternalPaymentCommand
{
    public function __construct(
        public Order $order,
        public bool $wholeCycle,               // true = advance one cadence step; false = use $paidThroughDate
        public ?\DateTimeImmutable $paidThroughDate, // required when !$wholeCycle
        public int $amount,                    // haléře, for the Payment row + invoice
        public bool $issueInvoice,
    ) {}
}
```

`src/Command/RecordExternalPaymentHandler.php` (command bus). Branch on whether a contract exists:

```php
$order = $command->order;
$now = $this->clock->now();
$contract = $this->contractRepository->findByOrder($order);

if (null !== $contract) {
    // CASE A — running manual-track cycle
    $originalNextBillingDate = $contract->nextBillingDate; // capture BEFORE advancing
    $paidThroughDate = $command->wholeCycle
        ? $originalNextBillingDate->modify($contract->getBillingCadenceStep())->modify('-1 day')
        : $command->paidThroughDate;

    $contract->recordExternalPayment($paidThroughDate, $now);

    if (null !== $originalNextBillingDate) {
        $req = $this->manualPaymentRequestRepository->findUnpaidByContractAndPeriod($contract, $originalNextBillingDate);
        $req?->markPaid($now);
    }

    $this->paymentRepository->save(new Payment(
        id: $this->identityProvider->next(),
        order: $order, contract: $contract, storage: $contract->storage,
        amount: $command->amount, paidAt: $now, createdAt: $now,
    ));

    if ($command->issueInvoice) {
        try {
            $this->invoicingService->issueInvoiceForRecurringPayment($contract, $command->amount, $now);
        } catch (\Throwable $e) {
            // Best-effort: a Fakturoid outage must not roll back the recorded payment.
            $this->logger->error('External-payment invoice failed', ['contract_id' => $contract->id->toRfc4122(), 'exception' => $e]);
        }
    }

    $this->auditLogger->log(entityType: 'contract', entityId: $contract->id->toRfc4122(),
        eventType: 'external_payment_recorded',
        payload: ['paid_through' => $paidThroughDate->format('Y-m-d'), 'amount' => $command->amount, 'whole_cycle' => $command->wholeCycle, 'invoice_issued' => $command->issueInvoice],
        orderId: $order->id, userIdContext: $order->user->id);
    return;
}

// CASE B — signed order still awaiting first payment (no contract yet)
$order->setPaymentMethod(PaymentMethod::EXTERNAL);
if (!$command->wholeCycle && null !== $command->paidThroughDate) {
    // Carried onto the contract as external prepayment by OrderService::completeOrder().
    $order->setOnboardingBillingTerms($order->individualMonthlyAmount, $command->paidThroughDate);
}
$this->orderService->confirmPayment($order, $now, $command->amount);
if ($order->hasAcceptedTerms()) {
    $this->commandBus->dispatch(new CompleteOrderCommand($order));
}
if ($command->issueInvoice) {
    $this->commandBus->dispatch(new IssueInvoiceForOrderCommand($order));
}
$this->auditLogger->log(entityType: 'order', entityId: $order->id->toRfc4122(),
    eventType: 'external_first_payment_recorded',
    payload: ['amount' => $command->amount, 'paid_through' => $command->paidThroughDate?->format('Y-m-d'), 'invoice_issued' => $command->issueInvoice],
    orderId: $order->id, userIdContext: $order->user->id);
```

Notes:
- `identityProvider` = `ProvideIdentity`. Inject `event.bus`? No — no domain event needed here; the invoice's own `InvoiceCreated` carries the customer e-mail. No separate customer notification (feature intentionally quiet unless an invoice is issued).
- Case B: `IssueInvoiceForOrderCommand` is dispatched **after** `CompleteOrderCommand`; both are established nested dispatches. `issueInvoiceForOrder` marks the invoice paid from `order.paidAt` (set by `confirmPayment`).

### 6. Feature 1 controller + form

`src/Controller/Admin/AdminOrderRecordExternalPaymentController.php` — `#[Route('/portal/admin/orders/{id}/record-external-payment', name: 'admin_order_record_external_payment', methods: ['GET','POST'])]`, `#[IsGranted('ROLE_ADMIN')]`.
- Load order; `denyAccessUnlessGranted(OrderVoter::VIEW, $order)`.
- **Eligibility gate** → flash error + redirect to `admin_order_detail` if not met: `order.hasSignature()` must be true AND (a running non-terminated contract with `nextBillingDate !== null` exists **or** `order.canBePaid()`).
- Build+handle `ExternalPaymentFormData` via `ExternalPaymentFormType`. On valid submit dispatch `RecordExternalPaymentCommand`, flash success (`Platba byla zaznamenána.` / incl. `Faktura byla vystavena.` when invoiced), redirect to `admin_order_detail`.
- GET (and invalid POST) renders `templates/admin/order/record_external_payment.html.twig`.

`src/Form/ExternalPaymentFormData.php`:
```php
final class ExternalPaymentFormData
{
    public string $coverage = 'whole_cycle';          // 'whole_cycle' | 'specific_date'
    public ?\DateTimeImmutable $paidThroughDate = null; // required iff coverage === 'specific_date'
    public ?float $amountInCzk = null;                  // defaults to RecurringAmountCalculator; koruny per spec 069
    public bool $issueInvoice = false;

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void { /* specific_date ⇒ paidThroughDate required & future & ≤ contract end; amount ≥ 0 */ }
}
```
- For **case B (first payment)** the form hides the coverage radios (there is no running cycle) — it's just amount + specific-date option + invoice checkbox. Controller passes a `firstPayment` flag to the template. Amount default = order `firstPaymentPrice`; for case A = `RecurringAmountCalculator::calculate`.
- `ExternalPaymentFormType`: `coverage` `ChoiceType` (radios), `paidThroughDate` datepicker (reuse `datepicker_controller.js`, `.form-input`), `amountInCzk` `NumberType` scale 2 `inputmode=decimal`, `issueInvoice` `CheckboxType`. Controller converts CZK→haléře (`(int) round($czk * 100)`) and maps `coverage` → `wholeCycle` bool.
- **Design system:** form controls use `.form-input`; radios/checkbox keep `h-4 w-4 … rounded` (see CLAUDE.md "NOT real DaisyUI"). All labels Czech with full diacritics.

### 7. Feature 2 controller + modal

`src/Controller/Admin/AdminContractExtendDeadlineController.php` — `#[Route('/portal/admin/contracts/{id}/extend-deadline', name: 'admin_contract_extend_deadline', methods: ['POST'])]`, `#[IsGranted('ROLE_ADMIN')]`. `{id}` = **contract** id.
- Load contract (`ContractRepository::get`), `denyAccessUnlessGranted(OrderVoter::VIEW, $contract->order)`.
- Parse `newDeadline` from the POST (a `y-m-d` field). Pre-validate (future; after `effectiveDunningAnchor`; contract not terminated; `nextBillingDate !== null`). On failure → flash error + redirect to `admin_order_detail` (id = `contract.order.id`).
- Dispatch `ExtendPaymentDeadlineCommand($contract, $newDeadline)`, flash success (`Splatnost byla prodloužena do DD. MM. YYYY.`), redirect to `admin_order_detail`.

Modal in `templates/admin/order/detail.html.twig` (a non-danger `<dialog>` mirroring the danger-modal markup minus the password field): a single required date input (`datepicker_controller.js`, `min-date` = tomorrow) posting to `admin_contract_extend_deadline`.

### 8. Cron + repository guards (Feature 2)

**8a.** `SendManualBillingPaymentRequestsCommand.php` — inside the loop, after the `isFree()` skip (L65):
```php
if ($contract->isInPaymentGrace($now)) {
    continue; // extension active: silence until the extended date
}
$anchor = $contract->effectiveDunningAnchor(); // re-anchors to a lapsed extension
if (null === $anchor) { continue; }
$stage = ManualBillingReminderSchedule::fromOrder($contract->order)->dueStageOn($now, $anchor);
```
Keep `periodStart: $contract->nextBillingDate` in the dispatched command (unchanged — the request row is still keyed on the real cycle; §2's `reopenForExtension` lets its stages replay).

**8b.** `ContractRepository::findManualBillingCandidates` — the coarse SQL pre-filter windows on `nextBillingDate BETWEEN now−90d AND now+90d`. A lapsed extension can push the *effective* due day outside that window while `nextBillingDate` stays put, so this still selects the row (grace only widens, never narrows) — **no SQL change needed**, but confirm with a test that a contract whose grace lapsed still surfaces (its `nextBillingDate` is by definition in the past ⇒ inside the window).

**8c.** `TerminateOverdueContractsCommand.php` + `ContractRepository::findOverdueForTermination` — terminate on the **effective** anchor:
- Repo query: add `AND (c.paymentGraceUntil IS NULL OR c.paymentGraceUntil <= :overdueSince)` and compare `COALESCE(c.paymentGraceUntil, c.nextBillingDate) <= :overdueSince`.
- Re-validation (L96-100): `$dueDate = $contract->effectiveDunningAnchor();` and keep the `$dueDate > $overdueSince` bail. `$daysOverdue` then measures from the effective anchor.

**8d.** `ProcessRecurringPaymentsCommand` + `RetryFailedPaymentsCommand` — skip `if ($contract->isInPaymentGrace($now))` in each per-contract loop (before charging / retrying). Prefer filtering in the candidate query if one exists; otherwise an in-loop guard is fine.

**8e.** `ContractRepository::findWithPaymentIssues` (admin digest) — add `AND (c.paymentGraceUntil IS NULL OR c.paymentGraceUntil < :now)` to the payment-issue branch so in-grace contracts drop off the admin overdue digest (the `outstandingDebtAmount` branch for terminated contracts is unaffected). Pass `:now`.

### 9. Detail-page buttons

In the "Manuální akce" panel of `templates/admin/order/detail.html.twig`:
- **"Označit jako externě zaplaceno"** — link to `admin_order_record_external_payment`, shown when `order.hasSignature()` and (`contract` with `contract.nextBillingDate is not null` and not terminated) or `order.canBePaid()`.
- **"Prodloužit splatnost"** — button opening the extend modal, shown when `contract` exists, not terminated, `contract.nextBillingDate is not null`.
- If `contract.paymentGraceUntil` is set, show an info line: `Splatnost prodloužena do DD. MM. YYYY` near the billing status.

## Acceptance

- [ ] Migration adds nullable `contract.payment_grace_until`; `doctrine:schema:validate` clean; `db:reset` works.
- [ ] **Feature 1 / case A:** on a running manual-track contract that is overdue, marking whole-cycle paid advances `nextBillingDate` by one cadence step, resets `failedBillingAttempts` to 0, marks the pending `ManualPaymentRequest` paid, writes a `Payment` row; specific-date mode sets `paidThroughDate` to the chosen date and `nextBillingDate` to the day after (or null past end). Invoice issued iff the box is ticked; a Fakturoid failure does not roll back the payment.
- [ ] **Feature 1 / case B:** a signed order in `AWAITING_PAYMENT` gets marked paid → `paymentMethod=EXTERNAL`, order `PAID`, `CompleteOrderCommand` creates the (signed) contract; custom date lands as `paidThroughDate`; invoice issued iff ticked.
- [ ] **Feature 1 gate:** an order with `hasSignature() === false` cannot reach the action (redirect + error flash); non-admin → 403; unauthenticated → `/login`.
- [ ] **Feature 2:** extending sets `paymentGraceUntil`; the manual-billing cron sends **no** reminder while `now ≤ paymentGraceUntil`; `TerminateOverdueContractsCommand` does **not** terminate while in grace; after the extended date, the dunning ladder re-fires from the extended date and termination is measured from it. A payment (external or FIO) clears `paymentGraceUntil`.
- [ ] Extend rejects a date ≤ today or ≤ current effective anchor (flash error, no mutation); non-admin → 403.
- [ ] Both new controllers have integration tests (auth redirect, non-admin 403, happy path, guard/validation failure). Cron integration tests prove grace suppression + post-grace re-anchoring + termination COALESCE.
- [ ] `composer quality` green **and** full `composer test` green (controller/cron/template changes are not covered by `composer quality` alone).
- [ ] Czech UI text with full diacritics; form controls use `.form-input`; no recurring-consent surface added.

## Out of scope

- **Multi-cycle invoice split.** A "specific date" that spans several cycles issues a single invoice for the entered amount (default = one cycle via `RecurringAmountCalculator`); the admin adjusts additional invoices in Fakturoid. Reason: per-cycle invoice generation for an arbitrary back-paid span is disproportionate for v1.
- **Customer notification of a bare (invoice-less) external payment.** No new e-mail; the invoice e-mail covers the invoiced case. Reason: the operator is recording a real-world event, not initiating customer contact.
- **Password gate on either action.** These are non-destructive (reversible by a later real payment / re-extension), unlike cancel/terminate. Reason: friction without a matching risk; the form/modal already require deliberate input.
- **FIO double-advance de-dup.** If a real transfer with the same VS arrives after a manual mark-paid, it reconciles the *next* cycle (benign, audit-visible). Reason: vanishingly rare, self-correcting, and building a cross-check is disproportionate.
- **Removing `paymentGraceUntil` on prolongation/cancellation.** Only a recorded payment clears it; a prolongation of an in-grace contract keeps the grace until paid. Reason: correct by construction (the extended cycle is still unpaid).
- **AUTO-card "extend" resetting the retry counter.** Grace only *suppresses* the retry cron; `failedBillingAttempts` is preserved so history survives. Reason: after the extended date the card should resume retrying from where it was.

## Open questions

None — proceed.
