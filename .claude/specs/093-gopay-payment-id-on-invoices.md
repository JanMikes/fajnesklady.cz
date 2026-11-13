# 093 — GoPay payment ID on every card-paid Fakturoid invoice (+ backfill)

**Status:** done (2026-11-13)
**Type:** feature + one-off data repair
**Scope:** medium (Fakturoid client ×2 (API + mock) + 1 update method, `InvoicingService` 5 paths, `Invoice` column + migration, invoice↔payment resolver refactor, 1 console command, docs, tests)
**Depends on:** 092 (credit notes, `createCreditNote`, storno `Payment` rows), 78ad0ef (`paidVia` / Fakturoid `payment_method`)

## Problem

The accountant cannot pair GoPay payouts with our invoices. A GoPay statement lists each transaction by **GoPay payment ID**, plus the `order_number` we sent to GoPay (our UUIDs: the order id, `FINE-<uuid>`, `CSU-…`). Neither appears anywhere on the Fakturoid invoice or in its ISDOC export:

- The VS is the **order's** VS, shared by the first payment, every recurring cycle, the debt and the credit notes of a contract.
- The invoice number is Fakturoid's own.

## Verified facts (live probe on the Fakturoid test account, 2026-11-13)

Fakturoid does not document its ISDOC mapping. I created a probe invoice on `testapiintegration` with a marker in every candidate field, extracted the embedded `invoice.isdoc` from its PDF (`pdfdetach`), then deleted the probe:

| Fakturoid field | ISDOC | PDF |
|---|---|---|
| `order_number` | `<OrderReferences><OrderReference><ExternalOrderID>` | "Číslo objednávky" |
| `note` | `<Note>` | text above the lines; **replaces the account-default note** |
| `custom_id`, `private_note`, `tags` | — | — |
| `footer_note` | — | footer |
| payment record `variable_symbol` | — (the ISDOC carries the invoice VS) | — |

A second probe confirmed that `PATCH order_number` works on an invoice **already marked paid**. The next PDF download carries the new value in both the ISDOC and the PDF, so a backfill is possible.

→ **`order_number` is the field.** It holds the **bare** GoPay payment ID (e.g. `9316985257`), with no prefix, so the accountant's software can match the exact string.

### Rejected alternatives

- **The VS:** the bank matcher resolves a transfer to the order by VS. A customer paying from the PDF anyway would send an unresolvable VS (see the comment in `FakturoidApiClient::paidDocumentFields`).
- **`note`:** it would overwrite the account-default note text and clutter the PDF. Kept as a fallback only if the accountant's import ignores `ExternalOrderID`.

## Context (current state)

- `FakturoidClient` / `FakturoidApiClient` (`src/Service/Fakturoid/`): `createInvoice`, `createDebtInvoice`, `createFineInvoice`, `createRecurringInvoice` and `createCreditNote` (092) all spread `paidDocumentFields(?string $variableSymbol, PaymentMethod $paidVia)` into the create payload. `updateInvoicePaymentMethod` is the existing PATCH precedent. The mock is `tests/Mock/MockFakturoidClient.php`.
- `InvoicingService` (`src/Service/InvoicingService.php`) has five issuing paths. Where each one gets the **paying** GoPay ID:
  - **First payment** (`issueInvoiceForOrder`): the order's first `Payment` (`PaymentRepository::findByOrder`, storno rows excluded) `->goPayPaymentId`. This is written by `RecordPaymentOnOrderPaidHandler` from `OrderPaid::$goPayPaymentId`. Fallback: `Order::$goPayPaymentId`, when `Order::$paidVia === GOPAY`. `markPaid()` sets GOPAY only from the webhook, and the webhook finds the order **by** that ID; a paid order never opens a new session.
  - **Recurring** (`issueInvoiceForRecurringPayment`, called from `IssueInvoiceOnRecurringChargeHandler`): `RecurringPaymentCharged::$goPayPaymentId`.
  - **Onboarding debt** (`issueInvoiceForDebt`): `Order::$debtGoPayPaymentId`, when `debtPaidVia === GOPAY` (the webhook finds the debt order by that ID).
  - **Fine** (`issueInvoiceForFine`): `Fine::$goPayPaymentId`, when `Fine::$paidVia === GOPAY` (same lookup argument).
  - **Credit note** (`issueCreditNote`): `$refund->payment->goPayPaymentId`. A GoPay refund lives under the original payment's ID, so the accountant pairs the refund with the same ID.
- `Invoice` has no link to the `Payment` it documents. `PaymentMethodBackfill::resolveInvoicePaymentMethod()` already pairs an invoice to its payment (same amount, closest `paidAt` within 24 h; debt by `debtPaidAt`; fine via the FK; storno rows skipped).
- `GoPayClient::getStatus()` returns `state` and `amount` (haléře), which is enough to verify a resolved ID before writing it to a tax document.

## Requirements

### R1 — New invoices carry the GoPay ID

1. Every `FakturoidClient` method that creates a customer document (`createInvoice`, `createDebtInvoice`, `createFineInvoice`, `createRecurringInvoice`, `createCreditNote`) gets a **required** `?string $goPayPaymentId` parameter right after `PaymentMethod $paidVia`. It is required so that no call site can silently forget it.
2. `paidDocumentFields()` adds `'order_number' => $goPayPaymentId` when the ID is non-null and non-empty. Otherwise the key is omitted (bank-transfer invoices keep an empty "Číslo objednávky"). `createSelfBillingInvoice` is unchanged.
3. `InvoicingService` passes the ID **only when the document's payment method is `GOPAY`**, from the sources listed in Context. For any other method it passes `null`.
4. New nullable column `Invoice::$goPayPaymentId` (`varchar(50)`, like `Payment`), set at construction from the same value. It is the local record of what Fakturoid got, and it makes the backfill idempotent. The migration is generated with `make:migration`, after 092's `Version20261113143234`.

### R2 — Backfill command `app:backfill-invoice-gopay-ids`

1. Iterates over invoices with `goPayPaymentId IS NULL`, oldest first (self-billing invoices are a separate entity and are never touched).
2. Resolves the paying source per invoice, through a shared resolver extracted from `PaymentMethodBackfill`:
   - **credit note** (`correctionOf` set) → the `PaymentRefund` whose `creditNote` is this invoice → `payment->goPayPaymentId`;
   - **fine** → `fine->goPayPaymentId` if `fine->paidVia === GOPAY`;
   - **otherwise**: the existing amount + 24 h matching. A debt candidate carries `order->debtGoPayPaymentId` (if `debtPaidVia === GOPAY`); a `Payment` candidate carries its own `goPayPaymentId`.
   - `resolveInvoicePaymentMethod()` keeps its behaviour by delegating to the new resolver.
3. **Verifies with GoPay** before writing anything. `getStatus(id)` must return a state in `PAID | REFUNDED | PARTIALLY_REFUNDED`. For a non-credit-note invoice, the status `amount` must also equal `invoice.amount`. A mismatch or a GoPay error means the invoice is reported and not written. (Old rows carried bank-transaction UUIDs in `go_pay_payment_id`; GoPay rejects those, so they fail verification.)
3a. **One payment, one invoice** (added after the independent review): GoPay confirms state and amount, not *which* invoice a payment paid. Two same-amount invoices of one order within the 24 h window can resolve to the same ID. A non-credit-note ID that resolves for more than one invoice in the run, or already sits on another non-credit-note invoice, is reported `AMBIGUOUS` for **every** invoice involved, and none of them is written. Credit notes legitimately share their payment's ID.
4. On success: `FakturoidClient::updateInvoiceGoPayPaymentId(fakturoidInvoiceId, id)` (PATCH `order_number`), then `Invoice::backfillGoPayPaymentId(id)`, flushed per invoice. A Fakturoid refusal (e.g. 403 `document_locked`) is reported and the local column stays null, so a rerun retries it.
5. Invoices whose source is not a card payment are counted and not listed (the bank-transfer and off-system ones). Unresolvable or unverified ones are listed with a reason, and the command exits `FAILURE` when there are any.
6. `--dry-run` resolves and verifies but writes nothing. `--csv=<path>` writes `invoice_number;issued_at;amount_czk;gopay_payment_id;result` for the accountant: rows that were updated, or would be in a dry run.

## Acceptance

- A new card-paid first payment / recurring / debt / fine / credit-note invoice is created in Fakturoid with `order_number` = the paying GoPay ID, and `invoice.go_pay_payment_id` stores it. Bank-transfer invoices have neither.
- `app:backfill-invoice-gopay-ids --dry-run` on production lists every historical card-paid invoice with its resolved and verified ID, and changes nothing.
- The real run updates Fakturoid and the column. A second run finds nothing to do.
- Unit tests cover the payload (`order_number` present / absent) and `InvoicingService` passing the right ID per path. Integration tests cover the backfill: resolution, the GoPay verification refusal, the Fakturoid refusal, dry-run and idempotency.

## Out of scope

- Showing the ID in admin or customer UIs.
- Bank-transfer invoices (the FIO movement ID could go into `order_number` the same way; not requested).
- Re-delivering already-imported documents to the accountant's software. Fakturoid regenerates the ISDOC on the next download; the accountant re-exports the affected periods or uses the `--csv` output.

## Open questions

- Does the accountant's software read ISDOC `OrderReference/ExternalOrderID` ("Číslo objednávky")? This is unverified for Pohoda / Money. If not, the fallback is to also write the ID into `note` (see Rejected alternatives).
