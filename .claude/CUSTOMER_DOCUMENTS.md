# Customer-facing documents

Index of every document a customer can encounter during the order lifecycle:
what it is, when it's generated/sent, where it lives in the codebase, and how
the customer accesses it. Source of truth is the code — this file is a quick
overview so we don't have to grep on every change.

## Inventory

| # | Document | Generator / Source | Storage location | Customer access |
|---|---|---|---|---|
| 1 | Faktura (PDF) | Fakturoid → `InvoicingService` | absolute path on disk via `Invoice.pdfPath` | `InvoicePdfController` (auth, per-invoice) + `OrderInvoiceDownloadController` (public, UUID-gated, primary invoice only) |
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

| Email | Trigger | Attachments | Links to |
|---|---|---|---|
| `email/order_confirmation.html.twig` | `OrderPlaced` (after acceptance, **pre-payment**) | signed contract (PDF or DOCX fallback), VOP, poučení spotřebitele, podmínky opakovaných plateb (if recurring) | portal order detail (`manageUrl`) |
| `email/contract_ready.html.twig` | `OrderCompleted` (**post-payment**, fired by webhook / payment return) | signed contract, mapa skladu (PNG), provozní řád pobočky (if any), návod pro zákazníky (if any) | public order success page with `#dokumenty` anchor (`orderUrl`) |
| `email/invoice.html.twig` | `InvoiceCreated` | invoice PDF | — |

**Naming note:** the `contract_ready` template name is historical; the contract is actually finalized in the *pre-payment* email (`order_confirmation`). Treat `contract_ready` as the "rental activated" email — it's about operational artefacts (map, operating rules) and the link to the documents hub. A future spec can rename the template + handler class for clarity.

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

- **Email rename**: `contract_ready` → something like `rental_active`
  (handler + template + tests). Not done now to limit blast radius.
- **Invoice consolidation**: today the invoice ships in its own e-mail; a
  future change can attach the invoice to the post-payment "rental active"
  e-mail when available, with a separate retry path if invoice generation
  is delayed (Fakturoid down, async issues, etc.). Less e-mails, but needs
  failover so the customer never ends up without the invoice.
- **Per-invoice public download route**: today `OrderInvoiceDownloadController`
  serves only the order's primary invoice (oldest by `issuedAt`). For
  anonymous viewing of multi-invoice recurring orders, add an
  `{orderId}/{invoiceId}` route. Logged-in users hit the portal route which
  already supports this.
