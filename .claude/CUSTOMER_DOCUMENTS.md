# Customer-facing documents

Index of every document a customer can encounter during the order lifecycle:
what it is, when it's generated/sent, where it lives in the codebase, and how
the customer accesses it. Source of truth is the code — this file is a quick
overview so we don't have to grep on every change.

## Inventory

| # | Document | Generator / Source | Storage location | Customer access |
|---|---|---|---|---|
| 1 | Faktura (PDF) | Fakturoid → `InvoicingService` (`issueInvoiceForOrder` / `issueInvoiceForRecurringPayment` / `issueInvoiceForDebt` — incl. the onboarding-debt invoice) | absolute path on disk via `Invoice.pdfPath` | `InvoicePdfController` (auth, per-invoice) + `OrderInvoiceDownloadController` (public, UUID-gated, primary invoice only) |
| 2 | Smlouva (DOCX) | `ContractDocumentGenerator` | `var/contracts/contract_{uuid}_{date}.docx` via `Contract.documentPath` | `ContractDownloadController` (auth) |
| 3 | Smlouva (PDF) | `DocumentPdfConverter` (on-the-fly from DOCX) | not persisted | `ContractPdfController` (auth) + `OrderContractDownloadController` (public, UUID-gated) |
| 4 | Mapa skladu (PNG, vyznačená jednotka) | `StorageMapImageGenerator::generate(Storage)` | not persisted (regenerated on every fetch) | `OrderMapDownloadController` at `/objednavka/{id}/dokumenty/mapa.png` (public, UUID-gated, COMPLETED only) |
| 5 | VOP (PDF, per-order, signed) | `VopDocumentGenerator` + `VopPdfStamper` | DOCX persisted at `var/vop/vop_{orderId}.docx`; PDF rendered on demand | `OrderVopDownloadController` (public, UriSigner-gated) + `VopPdfController` (auth, `OrderVoter::VIEW`) |
| 6 | Poučení spotřebitele (PDF) | static asset | `public/documents/pouceni-spotrebitele.pdf` | direct asset URL |
| 7 | Podmínky opakovaných plateb (PDF) | static asset | `public/documents/podminky-opakovanych-plateb.pdf` | direct asset URL — only relevant for recurring rentals (`order.endDate is null`) |
| 8 | Provozní řád pobočky (PDF/DOCX) | per-place upload | `public/uploads/{Place.operatingRulesPath}` | `upload_url()` Twig helper — only when `place.hasOperatingRules()` |
| 9 | Formulář odstoupení od smlouvy (PDF) | static asset | `public/documents/formular-odstoupeni-od-smlouvy.pdf` | direct asset URL |
| 10 | Reklamační formulář (PDF) | static asset | `public/documents/reklamacni-formular.pdf` | direct asset URL |
| 11 | Návod pro zákazníky (PDF/DOCX) | per-place upload (admin-only) | `public/uploads/{Place.instructionsPath}` | `upload_url()` Twig helper — only when `place.hasInstructions()` |

## Email touchpoints

Three customer-facing e-mails map onto three business events:

| # | Business event | Trigger event | Handler | Template | Subject |
|---|---|---|---|---|---|
| 1 | Customer signed and placed order (pre-payment) | `OrderPlaced` | `SendOrderPlacedEmailHandler` | `email/order_placed.html.twig` | `Potvrzení objednávky - <place>` |
| 2 | First payment received → rental active | `OrderCompleted` | `SendRentalActivatedEmailHandler` | `email/rental_activated.html.twig` | `Pronájem zahájen - <place>` |
| 3 | Standalone invoice (recurring charge OR first-payment fallback) | `InvoiceCreated` | `SendInvoiceEmailHandler` | `email/invoice.html.twig` | `Faktura <X> - Fajnesklady.cz` |

| Email | Attachments | Links to |
|---|---|---|
| step 1 (`order_placed`) | signed contract (PDF or DOCX fallback), VOP, poučení spotřebitele, podmínky opakovaných plateb (if recurring) | portal/public status URL (`statusUrl`) |
| step 2 (`rental_activated`) | **same legal pack as step 1** (signed contract, VOP, poučení, podmínky — byte-identical, attached via shared `OrderEmailAttachments` service) + mapa skladu (PNG), provozní řád (if any), návod pro zákazníky (if any), **invoice PDF (if Fakturoid issuance + PDF download succeeded synchronously)** | public order success / portal detail (`statusUrl`) |
| step 3 (`invoice`) | invoice PDF | order status link in body |

**How step-2 invoice bundling works.** `SendRentalActivatedEmailHandler` (1) looks up an existing `Invoice` for the order, (2) if none and `firstPaymentPrice > 0`, calls `InvoicingService::issueInvoiceForOrder` synchronously, (3) if the resulting invoice has a downloadable PDF on disk, attaches it inline and calls `Invoice::markEmailed($now)`. `SendInvoiceEmailHandler` short-circuits when `Invoice.emailedAt !== null` — so the standalone "Faktura" e-mail is suppressed for the happy first-payment path.

**When the standalone invoice e-mail still fires:**
- Every recurring monthly charge (`IssueInvoiceOnRecurringChargeHandler` creates the invoice, no `OrderCompleted` follows, `emailedAt` stays null).
- First-payment fallbacks: if Fakturoid was unreachable during `SendRentalActivatedEmailHandler`, the backstop cron `app:issue-missing-invoices` (5-min cadence, 15-min grace) issues the invoice later — `InvoiceCreated` then routes through `SendInvoiceEmailHandler` because `emailedAt` is still null.

**Onboarding-debt receipt + invoice (spec 073).** When an onboarding debt clears (GoPay webhook or FIO cron → `Order::markDebtPaid()` → `OnboardingDebtPaid`), `SendOnboardingDebtPaidEmailHandler` sends a separate **"Dluh uhrazen"** receipt (`email/debt_paid.html.twig`) and bundles a Fakturoid **debt invoice** issued via `InvoicingService::issueInvoiceForDebt` (gross amount = vč. DPH, `from_total_with_vat`), using the same attach-then-`markEmailed` trick as step 2 to suppress the standalone "Faktura" e-mail. Issuance is best-effort — a Fakturoid outage still sends the receipt without the attachment. There is **no** backstop for debt invoices (`app:issue-missing-invoices` only covers paid orders with no invoice, not debts); the handler swallows the error and the event succeeds, so a failed debt invoice is **not** auto-retried — the admin issues it manually in Fakturoid if needed. A sibling admin heads-up (`email/debt_paid_admin.html.twig`) goes to every `ROLE_ADMIN`.

**Why step 1 and step 2 ship the same legal pack.** Customers lose e-mails; reproducing the four legal documents in step 2 means whichever inbox they search, they find everything. `OrderEmailAttachments::attachLegalDocuments` is the single source of truth — both handlers call it, guaranteeing byte-identical attachments.

## Where the customer sees the full list

A single shared partial `templates/components/order_documents.html.twig`
renders the canonical "Vaše dokumenty" card on two pages:

- `templates/public/order_complete.html.twig` — public success page reachable
  at `/objednavka/{id}/dokonceno` after payment. UUID-gated, no auth needed.
  Logged-in owners are redirected to the portal page (where they get full
  navigation context). Anonymous customers and admins/landlords looking at
  someone else's order stay on the public page.
- `templates/portal/user/order/detail.html.twig` — authenticated portal page
  at `/portal/objednavky/{id}` for the order's owner. Shows the same panel
  for orders in `paid` or `completed` status.

The partial is auth-aware: anonymous viewers get UUID-gated public download
routes; logged-in viewers get per-invoice portal routes (so all monthly
invoices on a recurring contract are individually downloadable).

VOP is now per-order and bears the customer's signature on every body page;
form annexes (withdrawal, complaint) intentionally remain unsigned.

## Adding a new document type

1. Decide whether it's **static** (drop into `public/documents/`) or
   **generated** (build a service in `src/Service/`).
2. If generated, add a route in `src/Controller/Public/` for UUID-gated
   public access, or in `src/Controller/Portal/User/` if it requires auth.
3. Add a row to `templates/components/order_documents.html.twig`, gated on
   the right condition (e.g. recurring-only, place-feature-only).
4. If it should also be e-mailed: attach in the appropriate handler in
   `src/Event/Send*EmailHandler.php`.
5. **Update this file's inventory + email-touchpoints tables.**

## Future improvements

- **Per-invoice public download route**: today `OrderInvoiceDownloadController`
  serves only the order's primary invoice (oldest by `issuedAt`). For
  anonymous viewing of multi-invoice recurring orders, add an
  `{orderId}/{invoiceId}` route. Logged-in users hit the portal route which
  already supports this.
