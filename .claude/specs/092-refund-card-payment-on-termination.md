# 092 — Refund the card payment when an admin terminates a contract (+ Fakturoid credit note)

**Status:** done (2026-11-13)
**Type:** feature
**Scope:** large (~30 files: 1 new entity + 2 FK columns + migration, 2 client methods ×2 (API + mock), 1 pure calculator, 2 commands+handlers, 1 event + 1 e-mail handler + template, 1 console command, webhook changes, terminate controller/handler/template, payment read-site audit, audit labels, docs, tests)
**Depends on:** 087 (terminate modal + debt choice), 078/076 (billing periods, `paidThroughDate`), 081 (attach-then-`markEmailed` pattern), 78ad0ef (`paidVia` / Fakturoid `payment_method`)

## Problem

When a card-paying customer's contract has to be ended and their money returned (2026-11-13: customer on unit 24 must move to a storage with different terms; 1 750 Kč card payment `9316985257`, invoice `0009202611`), the whole money side is manual:

1. **No refund capability.** `GoPayClient` can create / query / void-recurrence, but cannot refund. The admin must find the payment in the GoPay console.
2. **No credit note.** `FakturoidClient` only issues invoices. The accountant must create the opravný daňový doklad by hand and link it to the original invoice.
3. **The app never learns about the refund.** Revenue dashboards, place finance, landlord self-billing, and the order's "Zaplaceno" figure keep counting the refunded money.
4. **A console refund is silently ignored by the webhook.** `ProcessPaymentNotificationHandler::__invoke` returns early on `existsByGoPayPaymentId()` ("duplicate notification"). Since 78ad0ef, **every** GoPay-paid row (first payment included — e.g. `payment.go_pay_payment_id = 9316985257`) has the id set, so the `REFUNDED` branch in the order path (`:155`) is effectively dead code. A refund done in the GoPay console therefore leaves no trace at all.

## Goal

In the existing **Ukončit smlouvu → Okamžitě** modal, an admin can tick **Vrátit platbu kartou**, choose **Poměrně ke dni** (date picker) or **Celou platbu**, and tick **Vystavit dobropis**. Submitting it:

1. Terminates the contract exactly as today (unchanged, committed first).
2. Refunds the computed amount via the GoPay API.
3. Records the refund in the app's money ledger, so every revenue total is net of refunds without touching the aggregate queries.
4. Optionally issues a Fakturoid credit note (`document_type: correction`, `correction_id` = original invoice).
5. Sends the customer a dedicated **"Vrácení platby"** e-mail with the credit note PDF attached.

Refunds the app did not initiate (GoPay console) are surfaced to admins instead of being swallowed.

## Implementation notes (deviations from the draft below, 2026-11-13)

- **Prorated amounts are rounded to whole koruny.** The app, the e-mails and the credit note all display whole Kč, so a haléř remainder would make them disagree. Example: 175000 × 20/27 = 129 629.6 haléře → **129 600** (1 296 Kč), not 129 630. Rounding to 0 Kč is rejected.
- **Refunds issued outside the app ARE booked automatically.** GoPay's refund history (`GET /payments/payment/{id}/refunds`) carries the amounts, so `PaymentRefundService::reconcile()` books SUCCESS total minus what the app already booked as a `RefundMode::EXTERNAL` refund. This writes a negative ledger row and a `payment_refund.refunded_externally` audit entry, and sends no customer e-mail or credit note. GoPay gives refunds no id, so matching works on totals, which is idempotent. The draft's "alert only, no ledger row" was superseded.
- **`PaymentRefund` is persisted only after GoPay accepted the refund** (`FINISHED`/`ACCEPTED`). A declined refund would otherwise stay in the UnitOfWork after the rollback and be flushed by the failure-recording dispatch as a phantom pending refund.
- **The DB guard** is a partial unique index `uniq_payment_refund_active_per_payment` on `payment_id WHERE status <> 'failed' AND mode <> 'external'`. Several console refunds of one payment stay possible.
- **Exceptions** live in `App\Exception` (`PaymentRefundFailed`, `RefundNotPossible`). `src/Service` is autowired.
- **Audit entity type `payment_refund`** with events `requested`, `succeeded`, `refunded_externally`, `failed`, `request_failed`, `credit_note_issued|skipped|failed`. The admin termination reason (already in the `admin_terminated_*` payload) is now rendered in the history too.
- **Console:** `app:reconcile-refunds` (hourly cron; `--gopay-payment=<id>` on demand).
- **`findByOrder()` / `findByContractAndPaidAt()` exclude storno rows.** Without that, `getOneOrNullResult()` would throw `NonUniqueResultException` once an order has a refund.

## Decisions (agreed with the operator, 2026-11-13)

- **Refund only as part of immediate admin termination.** No standalone "Vrátit platbu" action. If the refund step fails, the contract stays terminated and the admin finishes in the GoPay console (the flash message says so).
- **Prorated refund = admin-chosen date.** A date input labelled "Vrátit za dny od (včetně)", default **today**. That day and every day after it until the end of the paid period are refunded.
- **The credit note arrives in a separate customer e-mail** ("Vrácení platby"), not attached to the termination e-mail.
- **Card (GoPay) payments only.** Bank-transfer refunds are out of scope (no outgoing-payment integration); the toggle is not rendered when the refundable payment isn't a GoPay one.
- **Termination is committed before the refund is attempted** (separate bus dispatches). Reversed order risks a voided GoPay recurrence on a contract the DB still thinks is live. A GoPay refund cannot be undone, so it must never sit inside a transaction that might roll back the termination.

## Context (current state)

- **Terminate flow:** `AdminContractTerminateController` (`src/Controller/Admin/AdminContractTerminateController.php`, password-gated, reads `termination_type`, `reason`, `record_debt`) → `AdminTerminateContractCommand` → `AdminTerminateContractHandler` → `ContractService::terminateContract()` (voids the GoPay recurrence, terminates, releases storage, cancels pending manual requests) + `ContractTerminated` e-mail. Modal: `templates/admin/order/detail.html.twig` (terminate `_danger_modal` embed, `extra_fields` block with `termination_type` radios and the 087 debt choice). Variables built in `AdminOrderDetailController` (`canTerminate`, `potentialTerminationDebtInHaler` at :76-83).
- **Ledger:** `Payment` (`src/Entity/Payment.php`) — `amount` (haléře), `paidAt`, `order` **or** `contract` (the first payment row has `contract = null`, `period_start/end = null`), `goPayPaymentId` (unique where not null), `bankTransaction`, `periodStart`/`periodEnd` (`periodEnd` = `paidThroughDate` = next billing date, i.e. **exclusive**), virtual `paidVia`.
- **Revenue aggregates** (all `SUM(p.amount)` in `PaymentRepository`): `sumPaidByContract`, `sumPaidByOrder`, `sumPaidForOrder` (admin + landlord order detail "Zaplaceno"), `sumByStorageOwnerAndPeriod` (landlord dashboard), `sumAtPlaceForRange` / `sumAtPlaceAndPeriod` (place finance + dashboard), `sumAllByPeriod` (admin dashboard), `getMonthlyRevenue*`; self-billing reads `findUnbilledByStorageOwner`.
- **Invoices:** `Invoice` (`src/Entity/Invoice.php`) links `order` (+ optional `fine`), **not** a `Payment`. Records `InvoiceCreated` → `SendInvoiceEmailHandler` (short-circuits once `emailedAt` is set, which is the attach-then-`markEmailed` pattern used by debt/fine receipts). Issued by `InvoicingService` (`ensureFakturoidSubject`, stale-subject retry, best-effort PDF to `var/invoices/`). Customers reach invoices via `InvoicePdfController` (portal) and `OrderInvoiceDownloadController` (signed).
- **GoPay SDK** (`gopay/payments-sdk-php`): `Payments::refundPayment($id, int $amount)` → `POST /payments/payment/{id}/refund` (form, `amount` in haléře); response `{id, result}`, where `result` ∈ `FINISHED | ACCEPTED | FAILED`. Partial refunds allowed; payment state becomes `PARTIALLY_REFUNDED` / `REFUNDED`. `getHistoryRefunds($id)` → list with refund `state` ∈ `SUCCESS | FAILED | REQUESTED`.
- **Fakturoid API v3:** create invoice with `document_type: "correction"` + `correction_id: <original fakturoid id>`; Fakturoid back-links the original. Payment on a document: `POST invoices/{id}/payments.json` (`paid_on`).
- **Webhook:** `ProcessPaymentNotificationHandler` — duplicate guard at the top (`existsByGoPayPaymentId`), `GoPayPaymentStatus::isCanceled()` includes `REFUNDED`; `PARTIALLY_REFUNDED` matches neither `isPaid` nor `isCanceled`.
- **Test doubles:** `tests/Mock/MockGoPayClient.php`, `tests/Mock/MockFakturoidClient.php` (aliased in `config/services_test.php`).

## Requirements

### 1. Data model (migration via `make:migration`, never handwritten)

**New entity `PaymentRefund`** (`src/Entity/PaymentRefund.php`) — the refund *operation*:

| field | type | notes |
|---|---|---|
| `id` | Uuid | `ProvideIdentity` |
| `payment` | ManyToOne `Payment`, not null | the refunded card payment |
| `contract` | ManyToOne `Contract`, not null | |
| `amount` | int | haléře, > 0, ≤ `payment.amount` |
| `mode` | enum `RefundMode` (`full`, `prorated`) | |
| `refundFrom` | ?date | prorated only: first refunded day (inclusive) |
| `periodStart`, `periodEnd` | date | the payment period used for the calculation (`periodEnd` exclusive) |
| `status` | enum `PaymentRefundStatus` (`pending`, `succeeded`, `failed`) | |
| `issueCreditNote` | bool | admin's choice |
| `requestedBy` | ManyToOne `User` | admin |
| `requestedAt`, `resolvedAt` | datetime | |

- **At most one non-failed refund per payment.** Unique partial index on `payment_id WHERE status <> 'failed'`. This guards against a double submit or a double refund.
- Behaviour methods: `markSucceeded(now)` (records `PaymentRefunded` event), `markFailed(now)`.

**`Payment.refundOf`** — new nullable self-FK. A **succeeded** refund writes a **negative `Payment` row** (the "storno" in the ledger): `amount = -refund.amount`, `paidAt = resolvedAt`, same `order`/`contract`/`storage` as the original, `refundOf = original`, `goPayPaymentId = null`. With that row in place, **every existing `SUM(p.amount)` aggregate becomes net revenue with no query changes.** That includes landlord self-billing: a refund in a later month reduces that month's commission base.
- The `paidVia` hook gains a first arm: `null !== $this->refundOf => $this->refundOf->paidVia`.
- Add `isRefund(): bool`.

**`Invoice.correctionOf`** — new nullable self-FK. The credit note is stored as an `Invoice` row with `correctionOf = original invoice` and a **negative** `amount`. That way it automatically appears in every invoice list/download the customer and admin already have; labels change to "Dobropis č. X (k faktuře Y)". Add `isCreditNote(): bool`.

### 2. Payment read-site audit (mandatory, do it before writing new code)

A negative row must never be mistaken for evidence of payment. For every `Payment` read site, decide include/exclude and add `AND p.refundOf IS NULL` (or `p.amount > 0`) where the semantics are "the payment that paid X":
- `PaymentRepository::findByOrder`, `findByContractAndPaidAt`, `findUnlinkedFirstPayments`, `findByGoPayPaymentId` (refund rows have no GoPay id, but be explicit), `PaymentMethodBackfill`, `InvoicingService::firstPaymentMethod`, `OrderPaymentOverviewFactory` (**include** — render as a "Vrácení platby" row, see §8), `findAllForOrder` (include).
- `SUM` aggregates: **include** (that's the point).
- Grep `Payment::class` and `->amount` on payments repo-wide; list what you checked in the commit message.

### 3. Refundable payment + amount (pure, unit-tested)

**`App\Service\Refund\RefundCalculator`** (stateless):

- `findRefundablePayment(Contract): ?RefundablePayment`: the **latest** (by `paidAt`) non-refund `Payment` of the contract or its order, with `paidVia === GOPAY` and no non-failed `PaymentRefund`. It returns `null` if that latest payment isn't GoPay. An older card payment is never offered instead, because older periods are consumed.
  - Period: `payment.periodStart/periodEnd` when set. Otherwise, when this is the order's first payment and no later payment exists: `contract.startDate` → `contract.paidThroughDate`. Otherwise the period is unknown: only **full** mode is offered.
- `calculate(RefundablePayment, RefundMode, ?date refundFrom): int`:
  - `full` → `payment.amount`.
  - `prorated` → `s = periodStart`, `e = periodEnd` (exclusive), `d = max(refundFrom, s)`. `periodDays = days(s, e)`, `unusedDays = days(d, e)`, result `round(amount × unusedDays / periodDays)`. Throw a domain exception when `d >= e` (nothing to refund) or `periodDays <= 0`.
  - Day math in `Europe/Prague` calendar days (no `diff()->days` across DST surprises; reuse `BillingCalendar` helpers where they fit).
- Unit tests: full; prorated mid-period; `refundFrom` before period start → equals full; `refundFrom == e` → exception; first-payment fallback period; the 2026-11-13 case (period 13. 11. – 10. 12. 2026, 1 750 Kč, `refundFrom` 13. 11. → 1 750 Kč; `refundFrom` 20. 11. → `round(175000 × 20/27)` = 129 630).

### 4. GoPay client

`GoPayClient::refundPayment(string $paymentId, int $amount): GoPayRefundResult` (`FINISHED|ACCEPTED|FAILED`). It throws `GoPayException` on HTTP failure, like the other calls. `GoPayClient::getRefundState(string $paymentId): ?string` reads `getHistoryRefunds`, returns the newest refund's `state`. Implement in `GoPayApiClient` + `MockGoPayClient` (scriptable result).

### 5. Commands

**`RefundContractPaymentCommand(contractId, paymentId, mode, ?refundFrom, issueCreditNote, adminId)`** → handler:
1. Lock the payment's contract (`lockForUpdate`); re-run `findRefundablePayment` and check it still equals `paymentId` (stale-form guard).
2. `amount = calculate(...)`; create `PaymentRefund(pending)`.
3. `GoPayClient::refundPayment(goPayId, amount)`:
   - `FINISHED` → `RefundService::confirm(refund, now)`: `markSucceeded`, write the negative `Payment`, audit `payment_refund_succeeded`.
   - `ACCEPTED` → stays `pending`, audit `payment_refund_pending`. The reconciliation in §7 completes it.
   - `FAILED` / exception → throw `PaymentRefundFailed`. The transaction rolls back (nothing refunded, nothing recorded).
4. Audit `payment_refund_requested` (amount, mode, refundFrom, period, GoPay id).

**Failure recording outside the rolled-back transaction** (MESSENGER.md §3): the controller catches the unwrapped `PaymentRefundFailed` (via `HandlerFailureUnwrap::unwrap`) and dispatches a tiny `RecordPaymentRefundFailureCommand` that writes audit `payment_refund_failed` (+ error log). **Never** call `AuditLogger::log()` in the controller after a dispatch (CLAUDE.md lost-write trap).

### 6. Credit note + customer e-mail (event handler, best-effort Fakturoid)

`PaymentRefunded` event (from `markSucceeded`) → **`SendPaymentRefundedEmailHandler`**:
1. If `refund.issueCreditNote`: `InvoicingService::issueCreditNote(PaymentRefund): ?Invoice`:
   - Find the original invoice: `Invoice` with `order = payment's order`, `fine IS NULL`, `correctionOf IS NULL`, `amount = payment.amount`, `issuedAt` on the payment's `paidAt` day (Prague). **Exactly one match required.** Otherwise skip the credit note, audit `credit_note_skipped` (reason) and log a warning. The admin modal already showed this (§8), so it's not a surprise.
   - `FakturoidClient::createCreditNote(subjectId, originalFakturoidInvoiceId, amount, lineName, paidVia, variableSymbol): FakturoidInvoice`: `document_type: correction`, `correction_id`, `vat_price_mode: from_total_with_vat`, `payment_method` from `paidVia` (card), the order VS, one line `quantity: -1`, `unit_price: amount/100`, `vat_rate: $this->vatRate`. Line name: `Vrácení platby – nevyužité období dd.mm.yyyy – dd.mm.yyyy` (prorated) / `Vrácení platby za pronájem skladovací jednotky {číslo} ({pobočka})` (full). Use the same stale-subject retry and error-context logging as the siblings.
   - Mark the credit note settled on the refund date (`createPayment` with `paid_on`). Download the PDF (existing retry helper), store it, save an `Invoice(correctionOf: original, amount: -refund.amount)`.
   - **⚠ Verify on a real Fakturoid document before shipping:** the sign convention (`quantity: -1` vs a negative `unit_price`), that `show_already_paid_note_in_pdf` / the payment record render sensibly on a correction, and the PDF wording. Fakturoid has no sandbox here, so create one correction on a test subject, check it, and delete it. The implementer asks the operator before doing that.
2. Send the e-mail **"Vrácení platby – Fajnesklady.cz"** (`templates/email/payment_refunded.html.twig`): amount, period/date, "peníze se vrátí na kartu, ze které byla platba provedena; připsání obvykle trvá několik pracovních dní", storage + place, the operator identification footer like the other e-mails, credit note attached when issued. Then `creditNote->markEmailed(now)`, so `SendInvoiceEmailHandler` stays silent for it (081 pattern).
3. Fakturoid failure: log + audit `credit_note_failed`, **still send the e-mail** without the attachment. The money is already back, so the customer must hear about it. No backstop cron, same as debt/fine invoices; the accountant issues it by hand if needed.

### 7. Webhook + pending-refund reconciliation

In `ProcessPaymentNotificationHandler::__invoke`, **before** the duplicate guard returns:
- If a `Payment` exists for the GoPay id, fetch `getStatus`. If `state ∈ {REFUNDED, PARTIALLY_REFUNDED}`:
  - A `pending` `PaymentRefund` exists for that payment → `getRefundState`: `SUCCESS` → `RefundService::confirm`; `FAILED` → `markFailed` + audit `payment_refund_failed` + admin alert e-mail (reuse the `UnaccountedPaidPayment` alert style); `REQUESTED` → nothing.
  - A `succeeded` refund exists → no-op (normal echo of our own refund).
  - **No refund known** → it was done outside the app: error log + audit `payment_refunded_externally` **once** per GoPay id (check the audit log before writing; GoPay re-delivers). No ledger row is written automatically, because the amount is unknown and the admin reconciles.
- Otherwise keep today's duplicate short-circuit. The extra `getStatus` call only happens for known payments, which are rare re-deliveries.
- Fix the dead order-path `REFUNDED` branch comment accordingly (or route it into the same function).

**Console command `app:reconcile-pending-refunds`** (cron, hourly): for each `pending` refund older than 10 min, the same `getRefundState` → confirm / fail. This covers a lost webhook. Register it in the deploy cron config next to the other `app:*` jobs.

### 8. Admin UI (`templates/admin/order/detail.html.twig`, `AdminOrderDetailController`)

Controller passes `refundablePayment` (period, amount, paidAt, GoPay id, `canProrate`) and `refundCreditNoteInvoice` (matched original invoice or `null` + reason) when `canTerminate`.

Inside the terminate modal, **only within the "Okamžitě" branch** (shown/hidden by the same radio JS as `terminateDebtChoice`), when `refundablePayment` is not null:

```
[ ] Vrátit platbu kartou
    Platba 1 750 Kč ze dne 10. 11. 2026 (GoPay 9316985257), období 13. 11. – 10. 12. 2026
    (•) Poměrně — vrátit za dny od (včetně) [ 13.11.2026 ]   → Vrátí se: 1 750 Kč (27 z 27 dní)
    ( ) Celou platbu (1 750 Kč)
    [x] Vystavit dobropis k faktuře 0009202611 a poslat ho zákazníkovi
        — or, disabled: „Dobropis nelze vystavit: původní faktura nenalezena“
```

- Inputs: `refund` (checkbox), `refund_mode` (`prorated|full`), `refund_from` (date, default today), `refund_credit_note` (checkbox), hidden `refund_payment_id`. Prorated radio hidden when `!canProrate`.
- The live preview is inline JS using the same formula. The **server value is authoritative**, and the flash message reports the real amount.
- `.form-input` on the date input (not a DaisyUI class).

**Controller** (`AdminContractTerminateController`): validate refund inputs **before** dispatching anything (bad date / mode / payment id → error flash, nothing done). Then:
1. dispatch `AdminTerminateContractCommand` (unchanged);
2. if `immediate && refund`: dispatch `RefundContractPaymentCommand`. On success, flash `Smlouva byla okamžitě ukončena a zákazníkovi bylo vráceno X Kč.` (or `…vrácení X Kč čeká na potvrzení GoPay.`). On failure, flash a **warning**: `Smlouva byla ukončena, ale vrácení platby selhalo (důvod). Vraťte platbu ručně v administraci GoPay.` plus `RecordPaymentRefundFailureCommand`.
- Refund inputs are ignored for `with_notice`.

**Order detail payment overview:** render negative `Payment` rows as "Vrácení platby" (red, minus sign) and pending/failed `PaymentRefund`s as status rows ("Vrácení čeká na potvrzení GoPay" / "Vrácení selhalo"). Invoice lists (admin, portal, `/stav`) label credit notes "Dobropis č. X (k faktuře Y)".

### 9. Audit labels

New event types: `payment_refund_requested`, `payment_refund_succeeded`, `payment_refund_pending`, `payment_refund_failed`, `payment_refunded_externally`, `credit_note_issued`, `credit_note_skipped`, `credit_note_failed`. Add them to `templates/admin/order/_activity_timeline.html.twig` (labels + colours) and `src/Service/AuditLogDescriptionRenderer.php`.

### 10. Docs

- `.claude/CUSTOMER_DOCUMENTS.md`: new document "Dobropis (opravný daňový doklad)" + new e-mail touchpoint "Vrácení platby".
- `.claude/COMPLIANCE.md` (line ~70, "Cancellation does NOT retroactively refund"): add that an admin **may** refund at their discretion on termination. This is a goodwill or contractual settlement, not a change to the customer-facing rule.
- `.claude/MESSENGER.md` §6/§7: the webhook now inspects refund states for known payments.

## Tests

- **Unit:** `RefundCalculatorTest` (cases in §3); `PaymentRefund` invariants; `Payment::paidVia` for a refund row; Fakturoid payload builder for the credit note (sign, `correction_id`, `payment_method`).
- **Integration (`composer test`, not just `quality`):**
  - Terminate + refund prorated → contract terminated, `MockGoPayClient` received `refundPayment(id, expectedAmount)`, `PaymentRefund` succeeded, negative `Payment` row, `sumPaidForOrder` net, credit-note `Invoice` with `correctionOf`, e-mail with attachment, `markEmailed` set (no standalone invoice e-mail).
  - Terminate + refund full, no credit note → no Fakturoid call, e-mail without attachment.
  - GoPay `ACCEPTED` → pending; the webhook with `PARTIALLY_REFUNDED` + history `SUCCESS` → succeeded; with history `FAILED` → failed + alert.
  - GoPay `FAILED` → contract still terminated, no `PaymentRefund`/negative row, `payment_refund_failed` audit persisted, warning flash.
  - Double submit / stale `refund_payment_id` → rejected, single refund.
  - Latest payment is a bank transfer → no toggle rendered, a posted `refund=1` is rejected.
  - `with_notice` + `refund=1` → refund ignored.
  - Original invoice not found → credit note skipped, e-mail still sent.
  - Webhook: known payment in `REFUNDED` with no `PaymentRefund` → `payment_refunded_externally` audit written once across two deliveries.
  - Access: unauthenticated → `/login`; `ROLE_USER` and landlord → 403; wrong password → nothing refunded (existing gate).

## Out of scope

- Refunding bank-transfer payments, or refunding multiple/older payments in one go.
- A standalone refund action on already-terminated contracts (operator decision 2026-11-13).
- Back-filling the 2026-11-13 manual refund (unit 24) into the ledger: the operator handles that manually in GoPay + Fakturoid. Optionally a one-off guarded SQL insert of the negative `Payment` row afterwards; ask first.
- Linking `Invoice` ↔ `Payment` with a real FK for all invoice types (would replace the matching heuristic in §6). Worth a follow-up spec if the heuristic ever skips.

## Open questions

1. Fakturoid correction sign convention and the "already paid" rendering on a correction need the one-off live check described in §6.
2. Landlord self-billing: a refund in a month that's already self-billed reduces the *next* unbilled month's base, possibly to a negative total. No prod storages have a landlord owner today, so this is acceptable for now; revisit when landlords go live.
