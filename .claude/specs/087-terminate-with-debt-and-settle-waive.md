# 087 — Terminate-with-debt choice + admin debt settle/waive actions

**Status:** done
**Type:** feature
**Scope:** medium (~16 files: 1 entity, 4 commands+handlers, 3 controllers, 2 repo methods, 3 templates, 1 email, 2 audit-label sites, tests)
**Depends on:** 086 (external payment / extend-deadline actions live in the same "Manuální akce" block), 078 (overdue termination + `outstandingDebtAmount`), 055 (admin termination flow)

## Problem

The system is inconsistent about turning an overdue contract into a **recorded debt**, and offers no way to resolve one:

- The cron `app:terminate-overdue-contracts` (reason `PAYMENT_FAILURE`) computes `ContractService::calculateOutstandingDebt()` + `Contract::setOutstandingDebt()`, so the terminated contract keeps `outstandingDebtAmount`, stays in **Po splatnosti** (`OVERDUE_PREDICATE` second clause), and shows a "Dluh po ukončení smlouvy" row in the order's **Přehled plateb** (`OrderPaymentOverviewFactory.php:71-83`).
- But the **manual admin Void/Terminate action** (`AdminContractTerminateController` → `AdminTerminateContractHandler` → `ContractService::terminateContract(..., TerminationReason::ADMIN)`) records **no debt**. Voiding an overdue contract by hand makes the receivable silently vanish.
- There is **no admin action anywhere to view/settle/waive** a recorded debt. Clearing one this session required raw SQL on prod (`UPDATE contract SET outstanding_debt_amount = NULL` + cancel the stale `manual_payment_request` + a hand-inserted `audit_log` row).

Secondary bug surfaced while diagnosing: a terminated contract's **still-`pending` `ManualPaymentRequest`** (the unpaid cycle, e.g. 1 820 Kč) keeps rendering as an **OVERDUE** row in Přehled plateb (`buildRequestRow`, its `period_start` is in the past) **alongside** the prorated "Dluh po ukončení smlouvy" row (e.g. 485 Kč) — the same receivable double-counted, at two different amounts. This affects **both** the cron and admin termination paths, because neither cancels the request.

## Goal

An admin terminating a contract chooses whether to record the unpaid period as a debt or forgive it. A recorded post-termination debt is visible (Po splatnosti + order detail) and resolvable from the order detail via two actions — **Označit jako uhrazený** (money came in) and **Odepsat dluh** (write-off, password-gated) — both supporting **partial** amounts. The sidebar "Po splatnosti" badge always reconciles with the list because everything is driven by `outstandingDebtAmount` and no receivable is double-counted. No operator ever needs raw SQL to clear a debt.

## Context (current state)

- **Contract debt field:** `Contract::$outstandingDebtAmount` (`src/Entity/Contract.php:35`), `setOutstandingDebt(int)` (:178), `hasOutstandingDebt()` (:183). Only writer today is the overdue cron.
- **Termination:** `ContractService::terminateContract(Contract, now, TerminationReason = EXPIRED)` (`src/Service/ContractService.php:61`) — voids GoPay token, releases storage (handover-aware), `$contract->terminate()`, audit `terminated` + `released`. `calculateOutstandingDebt(Contract, terminationDate): int` (:91) = `unpaidDays × (monthlyRate / 30)`, `0` when `paidThroughDate >= terminationDate`.
- **Admin terminate command path:** `AdminTerminateContractCommand(contract, immediate, reason)` (`src/Command/AdminTerminateContractCommand.php`) → `AdminTerminateContractHandler` (`src/Command/AdminTerminateContractHandler.php`): immediate → `terminateContract(ADMIN)` + audit `admin_terminated_immediately` + dispatch `ContractTerminated`; with-notice → `requestTermination()` + audit `admin_termination_notice` + `TerminationNoticeRequested`.
- **Terminate controller:** `AdminContractTerminateController` (`src/Controller/Admin/AdminContractTerminateController.php`) — POST, `ROLE_ADMIN`, **already password-gated** via `PasswordConfirmation`, reads `termination_type` (`immediate|with_notice`) + optional `reason`.
- **Termination e-mail:** `ContractTerminated` → `SendContractTerminatedEmailHandler` (`src/Event/SendContractTerminatedEmailHandler.php`) already passes `hasOutstandingDebt` + `outstandingDebt` to `templates/email/contract_terminated.html.twig` (debt block at :51-54). **Does NOT tell the customer how to pay.** Reusable bank-details/QR body: `templates/email/_manual_billing_payment_body.html.twig`.
- **Reference "record external payment" (spec 086):** `RecordExternalPaymentHandler` (`src/Command/RecordExternalPaymentHandler.php`) — writes a `Payment(order:null, contract, storage, amount, paidAt, createdAt)` row via `PaymentRepository::save()` + optional `InvoicingService::issueInvoiceForRecurringPayment()` (best-effort). **BUT** its controller `AdminOrderRecordExternalPaymentController::isEligible()` requires `!$contract->isTerminated()` — so it cannot clear a post-termination debt. This spec's settle path mirrors its Payment/invoice code but is a distinct, terminated-contract path.
- **ManualPaymentRequest:** `src/Entity/ManualPaymentRequest.php` — statuses `pending|paid|cancelled|expired`; `markPaid(now)` (:100), `markExpired(now)` (:118); **no `cancel()` yet**. Repo: `src/Repository/ManualPaymentRequestRepository.php` (has `findUnpaidByContractAndPeriod`).
- **Payment overview:** `OrderPaymentOverviewFactory::buildRequestRow()` (`src/Service/Order/OrderPaymentOverviewFactory.php:232`) renders a `pending`/past-`period_start` request as **OVERDUE**; `STATUS_CANCELLED`/`STATUS_EXPIRED` requests render grey and are excluded from `overdueTotal`/`outstandingTotal` (:253-262, :92-101). The "Dluh po ukončení smlouvy" row renders from `outstandingDebtAmount` (:71-83).
- **Overdue surface:** shared `ContractRepository::OVERDUE_PREDICATE` (`src/Repository/ContractRepository.php:49`) — `outstandingDebtAmount > 0` clause already pulls recorded-debt contracts into badge (`countOverdueContracts`) + list (`findWithPaymentIssues` → `OverdueChecker`). No change needed; recording/clearing the field is sufficient.
- **Order detail "Manuální akce" block:** `templates/admin/order/detail.html.twig:595-674` (guard at :597, buttons + modals follow); the terminate danger-modal is at :851-895 (`termination_type` radios, `PasswordConfirmation`). Controller `AdminOrderDetailController` (`src/Controller/Admin/AdminOrderDetailController.php`) already builds `paymentOverview` and has a `$now` (`build(...)` at :135); injects services via constructor (:52).
- **Danger-zone gate:** `src/Service/Security/PasswordConfirmation.php` + `templates/components/_danger_modal.html.twig` (embed with `title`/`action`/blocks; renders the password input + submit). Already used by the terminate modal.
- **Audit render sites:** on-page timeline `templates/admin/order/_activity_timeline.html.twig` (`eventLabels` map ~:20-53, `eventIcons` ~:55-103, unknown → `entityType ~ '.' ~ eventType`); export `src/Service/AuditLogDescriptionRenderer.php` (unknown → `"<entityType> — <eventType>"`).
- **Money-input convention:** admin money inputs are koruny floats converted to haléře at the controller (`(int) round($czk * 100)`) — spec 069. `Contract::getEffectiveMonthlyAmount()` / `outstandingDebtAmount` stay haléře.

## Requirements

### 1. `Contract` — partial debt reduction (`src/Entity/Contract.php`)

Add one method next to `setOutstandingDebt()`:

```php
/**
 * Reduce a recorded post-termination debt by an exact amount (settle or waive).
 * Clears the field to null once fully covered so hasOutstandingDebt()/the
 * overdue predicate/the payment-overview debt row all drop off in lockstep.
 */
public function reduceOutstandingDebt(int $amountInHaler): void
{
    if (null === $this->outstandingDebtAmount || $this->outstandingDebtAmount <= 0) {
        throw new \DomainException('Contract has no outstanding debt to reduce.');
    }
    if ($amountInHaler <= 0) {
        throw new \InvalidArgumentException('Reduction amount must be positive.');
    }
    if ($amountInHaler > $this->outstandingDebtAmount) {
        throw new \DomainException('Reduction exceeds the outstanding debt.');
    }
    $remaining = $this->outstandingDebtAmount - $amountInHaler;
    $this->outstandingDebtAmount = $remaining > 0 ? $remaining : null;
}
```

Keep `setOutstandingDebt()` unchanged (cron + termination-with-debt use it).

### 2. Cancel the stale cycle at termination (fixes the double-count for BOTH paths)

`ManualPaymentRequest::cancel()` (`src/Entity/ManualPaymentRequest.php`) — mirror `markExpired`:

```php
public function cancel(): void
{
    $this->status = self::STATUS_CANCELLED;
}
```

`ManualPaymentRequestRepository::findPendingByContract(Contract): array` — QueryBuilder, `contract = :c AND status = 'pending'`.

`ContractService::terminateContract()` (`src/Service/ContractService.php:61`) — after `$contract->terminate(...)`, cancel every still-pending request (inject `ManualPaymentRequestRepository`):

```php
foreach ($this->manualPaymentRequestRepository->findPendingByContract($contract) as $request) {
    $request->cancel(); // the cycle is void — its receivable is now the terminated-contract debt (or forgiven)
}
```

This runs for the admin path (command bus) and the cron (`TerminateOverdueContractsCommand`, own `wrapInTransaction` flush) alike — no separate change to the cron. After this, Přehled plateb shows the cancelled cycle grey and only the `outstandingDebtAmount` row (if any) counts.

### 3. Termination-with-debt choice

- `AdminTerminateContractCommand` — add `public bool $recordDebt = false`.
- `AdminTerminateContractHandler` — in the `immediate` branch, **before** `terminateContract()`:

```php
if ($command->recordDebt) {
    $debt = $this->contractService->calculateOutstandingDebt($contract, $now);
    if ($debt > 0) {
        $contract->setOutstandingDebt($debt);
    }
}
$this->contractService->terminateContract($contract, $now, TerminationReason::ADMIN);
```

Reason stays `ADMIN` (per decision). Because the debt is set before `dispatch(ContractTerminated)`, the existing `SendContractTerminatedEmailHandler` reload sees it and the e-mail's debt block renders. `recordDebt` is ignored for `with_notice` (debt is only knowable at the eventual termination date). Include `record_debt` in the `admin_terminated_immediately` audit payload.
- `AdminContractTerminateController` — pass `recordDebt: $request->request->getBoolean('record_debt')` into the command.

### 4. E-mail: tell the customer how to settle (`templates/email/contract_terminated.html.twig`)

Extend the debt block (:51-54) so a recorded debt shows the amount **and** how to pay: variable symbol (`order.variableSymbol`), a bank-transfer instruction, and a contact line. Reuse the bank-details rendering from `_manual_billing_payment_body.html.twig` if it drops in cleanly; otherwise at minimum VS + `simek@fajnesklady.cz` contact + amount. Pass `variableSymbol` (+ any bank-detail vars the partial needs) from `SendContractTerminatedEmailHandler`. QR image is optional/nice-to-have.

### 5. Settle action — "Označit jako uhrazený" (money received)

- `SettleContractDebtCommand(Contract $contract, int $amountInHaler, bool $issueInvoice)` — `final readonly`.
- `SettleContractDebtHandler` (`#[AsMessageHandler]`) — mirror `RecordExternalPaymentHandler::recordRunningCyclePayment` for the Payment/invoice bits:
  - guard `$contract->isTerminated() && $contract->hasOutstandingDebt()` (else `\DomainException`);
  - `$contract->reduceOutstandingDebt($command->amountInHaler)`;
  - write `Payment(id: identity->next(), order: null, contract: $contract, storage: $contract->storage, amount: $command->amountInHaler, paidAt: now, createdAt: now)` via `PaymentRepository::save()` (money genuinely moved → landlord self-billing/commission stays correct);
  - if `$command->issueInvoice`: best-effort `InvoicingService::issueInvoiceForRecurringPayment($contract, $amount, now)` in try/catch (log with `'exception' => $e`, never roll back);
  - audit `contract` / `debt_settled`, payload `{amount, remaining: $contract->outstandingDebtAmount ?? 0, invoice_issued}`, `orderId: $contract->order->id`, `userIdContext: $contract->user->id`.
- Controller `AdminOrderSettleDebtController` — `POST /portal/admin/orders/{id}/settle-debt`, `requirements: ['id' => '[0-9a-f-]{36}']`, `#[IsGranted('ROLE_ADMIN')]`, `denyAccessUnlessGranted(OrderVoter::VIEW, $order)`. No password gate. Read `amount` (koruny float) → `(int) round($czk * 100)` + `issue_invoice` checkbox. Reject when contract not terminated / no debt / amount ≤ 0 / amount > `outstandingDebtAmount` (flash error, redirect). Dispatch, flash success (append "Faktura byla vystavena." when issuing), redirect `admin_order_detail`.

### 6. Waive action — "Odepsat dluh" (write-off, password-gated)

- `WaiveContractDebtCommand(Contract $contract, int $amountInHaler, ?string $reason)` — `final readonly`.
- `WaiveContractDebtHandler` — guard as above; `reduceOutstandingDebt($amount)`; **no Payment, no invoice** (invariant: no money moved → no revenue-bearing row); audit `contract` / `debt_waived`, payload `{amount, remaining, reason}`.
- Controller `AdminOrderWaiveDebtController` — `POST /portal/admin/orders/{id}/waive-debt`, `ROLE_ADMIN`, `OrderVoter::VIEW`, **`PasswordConfirmation::isValid()` gate** (invalid → flash error, redirect, no mutation). Same amount parsing/validation as settle + optional `reason` (trim, max 500). Dispatch, flash, redirect.

### 7. Order-detail UI (`templates/admin/order/detail.html.twig`)

- Add `{% set canSettleDebt = contract is not null and contract.isTerminated() and contract.hasOutstandingDebt() %}` and include it in the "Manuální akce" guard (:597).
- In the button row (near the 086 buttons), when `canSettleDebt`: **Označit dluh jako uhrazený** (opens a normal `<dialog>` modal) + **Odepsat dluh** (opens the `_danger_modal` embed). Show the current debt: `{{ (contract.outstandingDebtAmount / 100)|number_format(0, ',', ' ') }} Kč`.
- **Settle modal** (plain `<dialog>`, mirror `extendDeadlineModal` at :677): form → `admin_order_settle_debt`, money `amountInCzk` input prefilled `contract.outstandingDebtAmount / 100` (`inputmode=decimal`, `step=0.01`, `max` = debt-in-czk), `issue_invoice` checkbox, note that partial amounts leave the rest owed.
- **Waive modal** (`{% embed 'components/_danger_modal.html.twig' %}` mirroring the terminate modal at :859): `action: path('admin_order_waive_debt', {id: order.id})`, amount input prefilled to full debt, optional `reason` textarea, `_danger_modal` supplies the password field + submit; `submit_label` = "Odepsat dluh".
- **Terminate modal (:851-895):** add the debt choice, rendered only when `potentialTerminationDebtInHaler > 0`, inside the `immediate` branch — two radios (default = record): `record_debt=1` "Ukončit a evidovat dluh ({{ (potentialTerminationDebtInHaler/100)|number_format(0,',',' ') }} Kč)" vs `record_debt=0` "Ukončit bez dluhu (odpustit)". (Small JS to show it only when `termination_type=immediate` is selected, or render it under the immediate radio; server ignores `record_debt` for with-notice regardless.)
- `AdminOrderDetailController` — compute and pass `potentialTerminationDebtInHaler = (contract is not null and not contract.isTerminated()) ? contractService.calculateOutstandingDebt(contract, now) : 0` (inject `ContractService`; reuse the existing `$now`).

### 8. Audit labels

- `_activity_timeline.html.twig`: `eventLabels` += `'debt_settled': 'Dluh uhrazen (externě)'`, `'debt_waived': 'Dluh odepsán'`; `eventIcons` += `'debt_settled': 'green'`, `'debt_waived': 'amber'`.
- `AuditLogDescriptionRenderer::describe()`: add `'contract.debt_settled' => 'Dluh uhrazen (externě)'`, `'contract.debt_waived' => 'Dluh odepsán'`.

## Acceptance

- [ ] Admin immediate-terminating an overdue contract with **"evidovat dluh"** → `outstandingDebtAmount` set (= `calculateOutstandingDebt`), reason `ADMIN`, contract appears in Po splatnosti + "Dluh po ukončení smlouvy" row; the customer termination e-mail shows the amount + how to pay. With **"odpustit"** → no debt, vanishes as today.
- [ ] After any termination, the contract's previously-`pending` `ManualPaymentRequest` is `cancelled`; Přehled plateb shows it grey and the overdue/outstanding totals no longer double-count it (a cron-terminated contract with debt shows exactly one debt figure).
- [ ] "Označit jako uhrazený" reduces the debt by the entered amount, writes a `Payment` row (and an invoice when checked); a partial amount leaves the remainder owed and keeps the contract in Po splatnosti; full amount clears it (badge decrements).
- [ ] "Odepsat dluh" reduces/clears the debt with **no** `Payment` row; wrong password → nothing changes; the timeline shows "Dluh odepsán".
- [ ] Amount > current debt or ≤ 0 is rejected on both actions.
- [ ] Every new controller has integration tests: happy path, unauthenticated → `/login`, wrong role → 403, non-owner admin… (admins are global, so cross-tenant = a second admin still 200 — assert the `OrderVoter::VIEW` wiring), **waive with wrong password → no mutation**. Unit tests: `Contract::reduceOutstandingDebt` (decrement / clamp-to-null / guards) and `OrderPaymentOverviewFactory` no longer emits an overdue row for a cancelled request on a terminated contract.
- [ ] No migration (reuses `outstandingDebtAmount` + `manual_payment_request.status`).
- [ ] `composer quality` green **and** `composer test` green (controller/template/form changes).

## Out of scope

- **Onboarding debt** (`Order::$onboardingDebtInHaler`) settle/waive — it already has its own payment flow (`DebtPaymentService::confirmDebtPaid`, spec 051/073). This spec is only the post-termination `Contract::$outstandingDebtAmount`.
- **Customer-facing "pay my post-termination debt online"** flow — admins record settlement when money arrives; no new public GoPay/bank debt page here.
- **Debt on `with_notice` termination** — debt is only recorded on immediate termination (the amount isn't knowable until the future termination date; the cron handles whatever's overdue then).
- **Reversing an already-issued settlement invoice** — manual in Fakturoid, as with spec 086.
- **Changing `OVERDUE_PREDICATE` / OverdueChecker labelling** — recording/clearing `outstandingDebtAmount` already flows through them unchanged.

## Open questions

None — proceed.
