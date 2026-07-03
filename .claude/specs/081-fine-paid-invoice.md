# 081 — Issue a Fakturoid invoice when a fine is paid; e-mail it and surface it on order details

**Status:** done
**Type:** feature
**Scope:** medium (~13 files: Invoice entity + migration, Fakturoid client interface + impl + mock, InvoicingService, FinePaid e-mail handler + template, invoice repo, VM factory + VM, 3 templates, 2 controllers, CUSTOMER_DOCUMENTS.md, tests)
**Depends on:** none (mirrors shipped spec 073's debt-invoice pattern; touches the same fines lists as 053/080 but is independent)

## Problem

When a customer pays a smluvní pokuta (GoPay or bank transfer), they get a plain "Pokuta zaplacena" confirmation e-mail and nothing else — no invoice/receipt document is issued at all (Fakturoid is never called for fines), and nothing about the payment is downloadable afterwards. Rent, recurring charges, and onboarding debt all get a Fakturoid invoice (specs 034/073); fines are the only paid amount without one. The customer must dig through e-mail for any record, and there is no permanent online copy.

## Goal

The moment a fine is marked paid, a Fakturoid invoice is issued (marked paid immediately), its PDF is attached to the existing "Pokuta zaplacena" e-mail, and the document is permanently downloadable next to the fine row on every surface that lists fines for the customer — public `/stav` and portal order detail — plus the admin order-detail fines table. Fakturoid outage never blocks the confirmation e-mail (best-effort, identical to spec 073).

## Context (current state)

- **Fine payment paths**: GoPay webhook `src/Command/ProcessPaymentNotificationHandler.php:146` and FIO cron `src/Command/ProcessIncomingBankTransactionHandler.php:119` both call `Fine::markPaid()` (`src/Entity/Fine.php:99`), which records `FinePaid` (`src/Event/FinePaid.php` — carries `fineId` + `occurredOn`) → `src/Event/SendFinePaidEmailHandler.php` sends `templates/email/fine_paid.html.twig`. Both paths run on the command bus, so domain events dispatch correctly.
- **Pattern to mirror — spec 073 debt invoice**: `src/Event/SendOnboardingDebtPaidEmailHandler.php` — private `attachDebtInvoice()` issues via `InvoicingService::issueInvoiceForDebt()`, attaches the PDF bytes, calls `$invoice->markEmailed($now)` to suppress the standalone `SendInvoiceEmailHandler` (which guards on `isEmailed()` at `src/Event/SendInvoiceEmailHandler.php:41`), returns the invoice number for the template, and swallows all failures (Fakturoid outage never blocks the receipt).
- **Invoicing machinery**: `src/Service/InvoicingService.php` — `issueInvoiceForDebt(Order, now)` is the closest sibling (`:77-120`): ensure/recreate Fakturoid subject with `StaleFakturoidSubjectException` retry, create, `markInvoiceAsPaid` immediately, persist `Invoice`, best-effort PDF download to `$invoicesDirectory` via `storePdf()`. `src/Service/Fakturoid/FakturoidClient.php` interface + `FakturoidApiClient.php` (`createDebtInvoice` payload at `:174-192`: `vat_price_mode from_total_with_vat`, one line, `vat_rate => $this->vatRate`) + `tests/Mock/MockFakturoidClient.php`.
- **`Invoice` entity** (`src/Entity/Invoice.php`): order + user + fakturoidInvoiceId + number + amount; **no link to `Fine`** — this spec adds one (needed to render a per-fine download link; an order can have many fines and many invoices).
- **Download controllers need NO changes** — both already authorize exactly right for fine invoices:
  - Public signed: `src/Controller/Public/OrderInvoiceDownloadController.php` (`/objednavka/{id}/dokumenty/faktura/{invoiceId}.pdf`, UriSigner + invoice-belongs-to-order check). Signed URLs via `OrderStatusUrlGenerator::generateInvoiceDownload(Order, Invoice, bool $forDownload)` (`src/Service/OrderStatusUrlGenerator.php:120`).
  - Portal: `src/Controller/Portal/User/InvoicePdfController.php` (`/portal/faktury/{id}/pdf`, `OrderVoter::VIEW` on `invoice->order` — passes for customer, admin, and landlord).
- **Customer fine lists**:
  - Public `/stav`: `templates/public/order_status.html.twig:123-140` renders `vm.paidFines` (collapsible "Zaplacené pokuty (N)"); built in `src/Service/Order/OrderStatusViewModelFactory.php:175-199` (`unpaidFines`/`paidFines`/`finePaymentUrls` → `src/Service/Order/OrderStatusViewModel.php:56-60`).
  - Portal user order detail: `templates/portal/user/order/detail.html.twig:276-300` (paid badge "Zaplaceno d.m.Y" at `:293-296`); data from `src/Controller/Portal/User/OrderDetailController.php:108-115`.
  - Admin order detail fines table: `templates/admin/order/detail.html.twig:130-190` ("Způsob platby" cell at `:161-169`); data from `src/Controller/Admin/AdminOrderDetailController.php:93`.
- **Docs panels** list all order invoices already (`InvoiceRepository::findAllByOrder`, `src/Repository/InvoiceRepository.php:52`) — a fine invoice will automatically appear there labelled "Faktura č. X"; this spec only adds a distinguishing suffix (public: `OrderStatusViewModelFactory.php:110-123`; portal: `templates/components/order_documents.html.twig:65-79`).
- **VAT decision (user unavailable — defaulted, flagged)**: smluvní pokuta is not consideration for a taxable supply, so **`vat_rate: 0`** (mimo DPH), unlike debt/rent's 21 %. Implemented as one literal in `createFineInvoice` — trivially flippable to `$this->vatRate` if the accountant disagrees. Keep `vat_price_mode: 'from_total_with_vat'` anyway so a future rate flip can't reintroduce the spec-034 double-count bug.
- **Gotcha**: `.claude/CUSTOMER_DOCUMENTS.md` must gain a row (CLAUDE.md mandate) — fine invoice is a new customer-facing document.
- **Fixtures**: no FineFixtures exist; tests construct/persist `Fine` directly (see spec 080 note). `tests/Unit/Service/Fakturoid/FakturoidApiClientTest.php` exists for payload-shape tests (spec 034 pattern).

## Requirements

### 1. `src/Entity/Invoice.php` — nullable fine link + migration

Add an optional constructor param (after `user`), promoted per house style:

```php
#[ORM\ManyToOne(targetEntity: Fine::class)]
#[ORM\JoinColumn(nullable: true)]
private(set) ?Fine $fine = null,
```

All three existing `new Invoice(...)` sites in `InvoicingService` use named args — unaffected. Generate the migration via `docker compose exec web bin/console make:migration` (never handwrite).

### 2. `FakturoidClient` interface + `FakturoidApiClient` + `MockFakturoidClient` — `createFineInvoice`

```php
public function createFineInvoice(int $subjectId, Fine $fine): FakturoidInvoice;
```

`FakturoidApiClient` impl mirrors `createDebtInvoice(:174-215)` verbatim (same try/catch, stale-subject detection, logging context with `fine_id`), with the payload:

```php
'subject_id' => $subjectId,
'vat_price_mode' => 'from_total_with_vat',
'lines' => [
    [
        'name' => sprintf('Smluvní pokuta — %s (%s)', $fine->type->label(), $place->name),
        'quantity' => 1,
        'unit_price' => $fine->getAmountInCzk(),
        // Smluvní pokuta není předmětem DPH (není úplatou za plnění) — 0 %, ne $this->vatRate.
        'vat_rate' => 0,
    ],
],
```

(`$place = $fine->contract->storage->getPlace()`.) `MockFakturoidClient` gains the method mirroring its `createDebtInvoice`.

### 3. `src/Service/InvoicingService.php` — `issueInvoiceForFine`

```php
public function issueInvoiceForFine(Fine $fine, \DateTimeImmutable $now): Invoice
```

Copy of `issueInvoiceForDebt` with: user = `$fine->user`; create via `createFineInvoice` (+ stale-subject retry); `markInvoiceAsPaid($fakturoidInvoice->id, $fine->paidAt ?? $now)`; `new Invoice(order: $fine->contract->order, user: $user, fine: $fine, ...)`; best-effort PDF (warning log `'Failed to download fine invoice PDF'`).

### 4. `src/Event/SendFinePaidEmailHandler.php` — issue + bundle + link

Inject `InvoicingService` and `OrderStatusUrlGenerator`. Add private `attachFineInvoice(TemplatedEmail $email, Fine $fine, \DateTimeImmutable $now): ?string` mirroring `SendOnboardingDebtPaidEmailHandler::attachDebtInvoice(:84-110)` exactly (issue → guard `hasPdf`/`file_exists` → attach bytes as `faktura_{number}.pdf` → `markEmailed($now)` → return number; every failure logged + `null`). Call it with `$event->occurredOn`; extend context:

```php
'invoiceNumber' => $invoiceNumber,   // null when Fakturoid failed — template hides the row
'statusUrl' => $this->statusUrlGenerator->generate($fine->contract->order),
```

### 5. `templates/email/fine_paid.html.twig` — invoice row + permanent-access CTA

- In the summary table, add (only when `invoiceNumber` is not null): `Faktura č.: {{ invoiceNumber }} (v příloze)`.
- After "Děkujeme za úhradu.", add a short paragraph + button-style link (match the green `#16a34a` accent):

```twig
<p>Potvrzení i fakturu najdete kdykoliv také v detailu vaší objednávky:</p>
<p style="text-align: center;"><a href="{{ statusUrl }}" style="...">Zobrazit detail objednávky</a></p>
```

### 6. `src/Repository/InvoiceRepository.php` — per-fine lookup

```php
/**
 * @param list<Fine> $fines
 * @return array<string, Invoice> keyed by fine id (RFC 4122)
 */
public function findByFines(array $fines): array
```

QueryBuilder `WHERE i.fine IN (:fines)`, result keyed by `$invoice->fine->id->toRfc4122()`. Empty input → `[]` early return (avoid `IN ()`).

### 7. Public `/stav` — download link on paid fine rows

- `src/Service/Order/OrderStatusViewModelFactory.php`: after the paid/unpaid split (`:175-193`), build `$fineInvoiceUrls` — for each paid fine whose invoice exists and `hasPdf()`, `$this->statusUrlGenerator->generateInvoiceDownload($order, $invoice, forDownload: true)`. Pass as new VM arg.
- `src/Service/Order/OrderStatusViewModel.php`: new `public array $fineInvoiceUrls = []` (map fineId → signed URL) next to `finePaymentUrls` (`:60`).
- `templates/public/order_status.html.twig` paid-fines rows (`:131-139`): when `vm.fineInvoiceUrls[fine.id|raw] is defined`, render a small `Faktura (PDF)` anchor next to the "Zaplaceno d.m.Y" text.
- Docs list labelling (`OrderStatusViewModelFactory.php:114`): `'name' => 'Faktura č. '.$invoice->invoiceNumber.(null !== $invoice->fine ? ' (smluvní pokuta)' : '')`.

### 8. Portal user order detail — download link

- `src/Controller/Portal/User/OrderDetailController.php`: inject `InvoiceRepository`; pass `'fineInvoices' => $this->invoiceRepository->findByFines($fines)`.
- `templates/portal/user/order/detail.html.twig:293-296`: next to the "Zaplaceno" badge, when `fineInvoices[fine.id|raw] is defined and fineInvoices[fine.id|raw].hasPdf()`, link `Faktura` to `path('portal_user_invoice_pdf', {id: fineInvoices[fine.id|raw].id, download: 1})`.
- `templates/components/order_documents.html.twig:70/78`: append `(smluvní pokuta)` to the row name when `invoice.fine is not null`; keep line `:71`'s "Daňový doklad…" subtitle as-is for rent invoices but for fine invoices use `'Doklad o zaplacení smluvní pokuty'` (0 % VAT — not a daňový doklad in the rent sense).

### 9. Admin order detail fines table — invoice link

- `src/Controller/Admin/AdminOrderDetailController.php`: pass `'fineInvoices' => $this->invoiceRepository->findByFines($fines)` (inject repo if not present — it already loads invoices, check existing constructor).
- `templates/admin/order/detail.html.twig` fines table: in the "Způsob platby" cell (`:161-169`) or a new narrow cell, add `Faktura` link to `path('portal_user_invoice_pdf', {id: ...})` when the map has an entry with PDF (`OrderVoter::VIEW` passes for admins). Landlord panel from spec 080: if it exists by implementation time, add the identical link; if 080 is unimplemented, skip silently.

### 10. `.claude/CUSTOMER_DOCUMENTS.md` — inventory row

Add: fine invoice — generated via Fakturoid on fine payment (`InvoicingService::issueInvoiceForFine`), stored in `var/invoices/`, reaches the customer as an attachment to the "Pokuta zaplacena" e-mail and permanently via `/stav` (signed link) + portal order detail + docs panels.

### 11. Tests

- **Unit** `tests/Unit/Service/Fakturoid/FakturoidApiClientTest.php`: `createFineInvoice` payload — `vat_rate === 0` (literal, NOT the configured rate), `vat_price_mode === 'from_total_with_vat'`, `unit_price` equals the fine's CZK amount, name contains the type label (mirror the spec-034 payload tests).
- **Integration** (new `tests/Integration/Event/SendFinePaidEmailHandlerTest.php` or extend the existing fine-payment flow test): persist a paid `Fine`, invoke the handler with a `FinePaid` event → an `Invoice` row exists with `fine` set and `order = fine.contract.order`; mailer message has the PDF attachment (MockFakturoidClient path) and body contains the invoice number; the invoice is `isEmailed()` (standalone invoice mail suppressed).
- **Integration** portal order detail: paid fine + linked invoice with PDF → response contains the `portal_user_invoice_pdf` link; fine without invoice → no link, page renders fine.
- **Integration** `/stav`: paid fine + invoice → signed `public_order_invoice_download` URL present in the paid-fines section.
- Fakturoid-throws path: handler still sends the e-mail (no attachment, no invoice row) — assert no exception escapes.

## Acceptance

- [ ] Paying a fine (simulate via `FinePaid` handler invocation) creates a Fakturoid invoice marked paid, persists an `Invoice` linked to the fine, and the "Pokuta zaplacena" e-mail carries `faktura_{number}.pdf` + the invoice number + a working `/stav` CTA.
- [ ] Fakturoid outage: e-mail still goes out without attachment; error logged with `'exception' => $e`; no invoice row.
- [ ] `/stav` paid-fines list shows a working signed "Faktura (PDF)" link; portal order detail and admin order detail fines table show the portal PDF link; all three absent for fines without an invoice (pre-feature fines).
- [ ] Docs panels label the fine invoice "Faktura č. X (smluvní pokuta)".
- [ ] Migration generated via `make:migration`; `doctrine:schema:validate` clean.
- [ ] `composer quality` green; full `composer test` green (controller/template changes).

## Out of scope

- Backfill invoices for fines paid before this ships — low volume; admin issues those manually in Fakturoid if ever needed. Rows simply show no link.
- A backstop cron à la `app:issue-missing-invoices` for fines — the e-mail-handler path is the single issuance point; if it fails, the log + missing link is the signal. Add later only if outages actually bite.
- Landlord-facing fine invoice surfaces beyond the spec-080 note — landlord fine visibility is 080's concern.
- Cancelling/crediting the Fakturoid invoice when an admin cancels a fine — cancellation is only possible while unpaid (`Fine::isPayable()` guard), and unpaid fines never get an invoice.
- Changing fine amount display anywhere (vč. DPH etc.) — fines are penalties, not prices; display unchanged.

## Open questions

None — proceed. (VAT defaulted to 0 % mimo DPH per Czech treatment of contractual penalties; the rate is a single literal in `createFineInvoice` — confirm with the accountant and flip to 21 % `$this->vatRate` if they disagree.)
