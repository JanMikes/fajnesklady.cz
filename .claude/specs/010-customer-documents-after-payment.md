# 010 — Customer documents available after successful payment (success page + email link)

**Status:** ready
**Type:** feature (UI section + new map-download endpoint + email tweak + documentation)
**Scope:** medium (~8 files: 1 new partial, 1 new controller, 1 new docs file; modifications to success page + portal order detail + contract_ready email handler & template)
**Depends on:** none

## Problem

After a successful payment the customer lands on `/objednavka/{id}/dokonceno` (`OrderCompleteController` → `templates/public/order_complete.html.twig`). That page tells them "thanks, all set" but offers **no way to download anything** — no contract, no invoice, no map, no terms. The follow-up email (`email/contract_ready.html.twig`, sent by `SendContractReadyEmailHandler` on `OrderCompleted`) attaches the contract DOCX + operating rules and links to the portal dashboard, but doesn't expose the full set of documents the customer has consented to and now has the right to keep.

Meanwhile, the documents themselves already exist somewhere — invoice PDF, contract DOCX/PDF, generated highlighted-storage map, VOP, consumer notice, recurring-payments terms, operating rules, withdrawal form, complaint form. They're scattered across services, static files, and a couple of portal-only pages. The customer-facing landscape is not documented.

## Goal

After successful payment the customer can — both on the success page and via a single button in their email — see a clean "Vaše dokumenty" panel with every document relevant to their order, each with a Download/Open button. The same panel renders on the logged-in portal order detail. A new file under `.claude/` documents the canonical list (what is each document, when is it produced, where is it stored, how is it served).

## Context (current state)

### Identifying the page
- The flow is: `/objednavka/.../` (form) → `/objednavka/.../prijmout` (recapitulation, signs + accepts terms) → `/objednavka/{id}/platba` (pay) → `/objednavka/{id}/platba/navrat` (GoPay return + status processing in `PaymentReturnController:35-58`) → **`/objednavka/{id}/dokonceno`** (success page, this spec's target). The recapitulation is `order_accept.html.twig`; the post-payment page is `order_complete.html.twig` — those are different pages and the user sometimes conflates them.
- `src/Controller/Public/OrderCompleteController.php` requires `OrderStatus::COMPLETED` and is **unauthenticated** (no `#[IsGranted]`). Reachable by anyone with the order UUID — same exposure as `OrderPaymentController` and the rest of `/objednavka/{id}/...`.

### Document surface (verified by grepping the repo)

| # | Document | Source / generator | Storage | Currently served from |
|---|---|---|---|---|
| 1 | **Faktura (PDF)** | `Fakturoid` API → `Invoice.pdfPath` filesystem | absolute path on disk | `InvoicePdfController` at `/portal/faktury/{id}/pdf` (auth) |
| 2 | **Smlouva (DOCX)** | `ContractDocumentGenerator` → `Contract.documentPath` | `var/contracts/contract_{uuid}_{date}.docx` | `ContractDownloadController` at `/portal/smlouvy/{id}/stahnout` (auth) |
| 3 | **Smlouva (PDF)** | on-the-fly `DocumentPdfConverter` from the DOCX | not persisted | `ContractPdfController` at `/portal/smlouvy/{id}/pdf` (auth) |
| 4 | **Mapa skladu s vyznačenou jednotkou (PNG)** | `StorageMapImageGenerator::generate(Storage)` | not persisted (returns bytes) | currently only attached to `order_confirmation` email; **no download endpoint exists** |
| 5 | **Všeobecné obchodní podmínky (VOP, PDF)** | static | `public/documents/vop.pdf` | direct asset URL `/documents/vop.pdf` |
| 6 | **Poučení spotřebitele (PDF)** | static | `public/documents/pouceni-spotrebitele.pdf` | direct asset URL |
| 7 | **Podmínky opakovaných plateb (PDF)** | static | `public/documents/podminky-opakovanych-plateb.pdf` | direct asset URL — **only relevant when `order.endDate is null`** (recurring rental) |
| 8 | **Provozní řád pobočky (PDF or DOCX)** | per-place upload via `Place.operatingRulesPath` | `public/uploads/{path}` | `upload_url(place.operatingRulesPath)` Twig helper — **only when `place.hasOperatingRules()`** |
| 9 | **Formulář odstoupení od smlouvy (DOCX)** | static | `public/documents/formular-odstoupeni-od-smlouvy.docx` | direct asset URL |
| 10 | **Reklamační formulář (DOCX)** | static | `public/documents/reklamacni-formular.docx` | direct asset URL |

Verified `ls public/documents/`:
```
formular-odstoupeni-od-smlouvy.docx
podminky-opakovanych-plateb.pdf
pouceni-spotrebitele.pdf
reklamacni-formular.docx
vop.pdf
```

### What gets emailed today
- **`SendOrderConfirmationEmailHandler`** (on `OrderCreated`, **pre-payment**): attaches map PNG, VOP, consumer notice, recurring-payments terms (if recurring), operating rules (if any).
- **`SendContractReadyEmailHandler`** (on `OrderCompleted`, **post-payment**): attaches contract DOCX, operating rules. Links to portal dashboard via `portalUrl`. Does NOT include invoice / map / VOP / consumer notice.
- **`SendInvoiceEmailHandler`** (on `InvoiceCreated`): attaches the invoice PDF separately.

### Portal order detail (existing partial behavior)
- `templates/portal/user/order/detail.html.twig:220-299` already renders: contract DOCX/PDF download buttons + invoice list. Status filter `order.status.value in ['paid', 'completed']`. Uses `portal_user_contract_pdf`, `portal_user_contract_download`, `portal_user_invoice_pdf` routes. Bound to `contract` and `invoices` Twig vars passed by `OrderDetailController`.

### Twig helpers
- `asset('documents/vop.pdf')` resolves to the public asset URL — works for the static legal PDFs and the two DOCX forms.
- `upload_url(place.operatingRulesPath)` is the established helper for per-place uploads (used at `templates/public/order_accept.html.twig:481`).

## Architecture

```
OrderCompleted event
        │
        ├─ SendContractReadyEmailHandler
        │     ├─ keeps: contract DOCX + operating rules attachments
        │     └─ adds:  documentsUrl  →  email button "Stáhnout všechny dokumenty"
        │                                      │
        │                                      ▼
        │                          /objednavka/{id}/dokonceno  (success page)
        │
        └─ … (existing handlers untouched)

Success page (order_complete.html.twig)
   └─ {% include 'components/order_documents.html.twig' with { order, contract, invoices, place } %}
                            │
                            ▼
   one card listing every applicable document with a download button:
     1. Smlouva (DOCX, PDF)         → portal_user_contract_download / portal_user_contract_pdf  *
     2. Faktura (PDF) per invoice    → portal_user_invoice_pdf  *
     3. Mapa skladu (PNG)            → public_order_map_image       (NEW)
     4. Provozní řád (if any)        → upload_url(...)
     5. VOP (PDF)                    → /documents/vop.pdf
     6. Poučení spotřebitele (PDF)   → /documents/pouceni-spotrebitele.pdf
     7. Podmínky opakovaných plateb  → /documents/podminky-opakovanych-plateb.pdf  (only if recurring)
     8. Formulář odstoupení          → /documents/formular-odstoupeni-od-smlouvy.docx
     9. Reklamační formulář          → /documents/reklamacni-formular.docx

   * routes are #[IsGranted('ROLE_USER')]. See "Auth boundary" in Requirement 2.

Portal order detail (templates/portal/user/order/detail.html.twig)
   └─ replaces existing invoice/contract sections with the same partial
```

## Requirements

### 1. New partial `templates/components/order_documents.html.twig`

Single source of truth for the customer-facing document list. Takes `order`, `contract` (nullable), `invoices` (list), `place` and renders a card with grouped download buttons.

Render groups in this order:

1. **Smlouva** — Smlouva (PDF) primary button + Smlouva (DOCX) secondary button. Only when `contract and contract.hasDocument()`. Disabled greyed-out fallback when not yet generated, mirroring the existing pattern at `templates/portal/user/order/detail.html.twig:249-256`.
2. **Faktura** — for each `invoice in invoices` with `invoice.hasPdf()`, one row + download PDF button. Identical look to the current `templates/portal/user/order/detail.html.twig:269-292` rows. Skip the section entirely if no invoices.
3. **Pobočka** — Mapa skladu PNG download (only when `place.mapImagePath` is set), Provozní řád link (only when `place.hasOperatingRules()`).
4. **Smluvní dokumenty** — VOP PDF, Poučení spotřebitele PDF, Podmínky opakovaných plateb PDF (only when `order.endDate is null`).
5. **Užitečné formuláře** — Formulář odstoupení od smlouvy (DOCX), Reklamační formulář (DOCX).

Use the existing card / button visual styles from `templates/portal/user/order/detail.html.twig` (rounded card, icon + label + button on the right). Group headings as small `<h3>` rows with a divider between groups; section gets a one-line description "Všechny dokumenty Vaší objednávky pohromadě." in the card header.

Render each document row consistently with three slots:
- icon (file/document SVG; recurring-payments and operating-rules can use a slightly differentiated icon)
- title + one-line description
- action button (`Stáhnout PDF` / `Stáhnout DOCX` / `Otevřít v prohlížeči`)

External-document buttons (`/documents/...`) get `target="_blank" rel="noopener"`. Internal portal routes are same-tab.

Skeleton:

```twig
<div class="card">
    <div class="card-header bg-gray-50 px-6 py-4 border-b">
        <h2 class="text-xl font-bold text-gray-900">Vaše dokumenty</h2>
        <p class="text-sm text-gray-600 mt-1">Všechny dokumenty Vaší objednávky pohromadě. Doporučujeme si je uložit.</p>
    </div>
    <div class="card-body divide-y divide-gray-100">
        {# 1. Contract group #}
        {% if contract %}
            <div class="py-4">
                <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3">Smlouva</h3>
                {# … contract PDF + DOCX rows, mirroring lines 220-260 of portal/user/order/detail.html.twig … #}
            </div>
        {% endif %}

        {# 2. Invoices group — skip if empty #}
        {% if invoices|length > 0 %}
            <div class="py-4">
                <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3">Faktury</h3>
                {% for invoice in invoices %}
                    {# … invoice row, mirroring lines 269-292 … #}
                {% endfor %}
            </div>
        {% endif %}

        {# 3. Place documents — map + operating rules #}
        {% if place.mapImagePath or place.hasOperatingRules() %}
            <div class="py-4">
                <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3">Pobočka</h3>
                {% if place.mapImagePath %}
                    {# … map PNG row, link path('public_order_map_image', {id: order.id}) … #}
                {% endif %}
                {% if place.hasOperatingRules() %}
                    {# … provozní řád row, link upload_url(place.operatingRulesPath) … #}
                {% endif %}
            </div>
        {% endif %}

        {# 4. Legal docs #}
        <div class="py-4">
            <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3">Smluvní dokumenty</h3>
            {# VOP, consumer notice rows #}
            {% if order.endDate is null %}
                {# recurring payments terms row #}
            {% endif %}
        </div>

        {# 5. Useful forms #}
        <div class="py-4">
            <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3">Užitečné formuláře</h3>
            {# odstoupení, reklamační formulář #}
        </div>
    </div>
</div>
```

### 2. New endpoint for the highlighted map PNG

Create `src/Controller/Public/OrderMapImageController.php`:

```php
#[Route('/objednavka/{id}/mapa.png', name: 'public_order_map_image', methods: ['GET'])]
final class OrderMapImageController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly StorageMapImageGenerator $mapImageGenerator,
    ) {}

    public function __invoke(string $id): Response
    {
        if (!Uuid::isValid($id)) {
            throw new NotFoundHttpException('Objednávka nenalezena.');
        }

        $order = $this->orderRepository->find(Uuid::fromString($id));

        if (null === $order) {
            throw new NotFoundHttpException('Objednávka nenalezena.');
        }

        // Only completed (paid) orders expose the map; mirrors OrderCompleteController's gate.
        if (OrderStatus::COMPLETED !== $order->status) {
            throw new NotFoundHttpException('Mapa není k dispozici.');
        }

        $imageData = $this->mapImageGenerator->generate($order->storage);

        if (null === $imageData) {
            throw new NotFoundHttpException('Pobočka nemá mapový podklad.');
        }

        $response = new Response($imageData, Response::HTTP_OK, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=3600',
        ]);
        $response->headers->set(
            'Content-Disposition',
            HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, 'mapa-skladu.png'),
        );

        return $response;
    }
}
```

**Auth boundary**: unauthenticated, gated by `OrderStatus::COMPLETED` and the order UUID. Same access model as `OrderCompleteController` — anyone with the order UUID + a completed order can fetch it. Consistent with the rest of the `/objednavka/{id}/...` route family. The map data itself is not particularly sensitive (place layout); the customer's name is not on the image.

The existing portal-only routes for invoice/contract (`portal_user_invoice_pdf`, `portal_user_contract_pdf`, `portal_user_contract_download`) remain `#[IsGranted('ROLE_USER')]`. Inside the partial, those buttons are rendered regardless of auth context — when the success page is viewed by a not-yet-logged-in customer, clicking those buttons will redirect them to login. The Czech-language flash from the firewall's redirect works fine here. Optional polish: add `{% if app.user %}` guards around those buttons on the success page, with a "Přihlaste se pro stažení smlouvy/faktury" prompt — see "Out of scope".

### 3. Wire the partial into the success page

In `templates/public/order_complete.html.twig`, insert the partial below the existing "Detail smlouvy" card and above the "Co dál?" card:

```twig
{# Documents — NEW #}
<div id="dokumenty" class="mb-6">
    {% include 'components/order_documents.html.twig' with {order, contract, invoices, place} %}
</div>
```

`OrderCompleteController` currently passes `order, contract, storage, storageType, place`. Add `invoices`:

```php
return $this->render('public/order_complete.html.twig', [
    // …existing…
    'invoices' => $this->invoiceRepository->findAllByOrder($order),  // see note
]);
```

The existing `InvoiceRepository::findByOrder(Order)` returns a single `?Invoice` — for the order-create + immediate-completion flow there's typically one. To keep semantics future-proof (recurring orders accumulate multiple invoices over time), add a `findAllByOrder(Order): array<Invoice>` method to `InvoiceRepository`. For initial render of `order_complete` it'll be a 1-element array.

```php
public function findAllByOrder(Order $order): array
{
    return $this->entityManager->createQueryBuilder()
        ->select('i')
        ->from(Invoice::class, 'i')
        ->where('i.order = :order')
        ->setParameter('order', $order)
        ->orderBy('i.issuedAt', 'DESC')
        ->getQuery()
        ->getResult();
}
```

The anchor `id="dokumenty"` lets the email link land on this section.

### 4. Replace duplicated section in portal order detail with the partial

In `templates/portal/user/order/detail.html.twig`, replace the existing contract + invoices section (lines 219–299) with `{% include 'components/order_documents.html.twig' with {order, contract, invoices, place} %}`. The portal controller already passes those vars. Net effect: the portal page now also shows VOP / consumer notice / map / forms — same one-stop document hub.

Verify `OrderDetailController` (`src/Controller/Portal/User/OrderDetailController.php`) already passes `place` to the template; if not, add it. (Quick check during implementation.)

### 5. Add a "Stáhnout všechny dokumenty" button to `email/contract_ready.html.twig`

In `src/Event/SendContractReadyEmailHandler.php`, add a new context variable:

```php
$documentsUrl = $this->urlGenerator->generate(
    'public_order_complete',
    ['id' => $contract->order->id->toRfc4122()],
    UrlGeneratorInterface::ABSOLUTE_URL
).'#dokumenty';
```

Pass it into `->context([...])`. Keep the existing `portalUrl` and the existing contract DOCX + operating-rules attachments unchanged.

In `templates/email/contract_ready.html.twig`, replace the existing "Smlouvu ve formátu DOCX si můžete stáhnout ve svém účtu" paragraph + button (around the `Zobrazit smlouvu` button) with two side-by-side buttons (or stacked on narrow):

```twig
<p>Pro stažení všech dokumentů Vaší smlouvy klikněte zde:</p>

<div style="text-align: center;">
    <a href="{{ documentsUrl }}" class="button">Stáhnout všechny dokumenty</a>
</div>

<p style="margin-top: 16px;">Své objednávky můžete také kdykoli spravovat ve svém účtu:</p>

<div style="text-align: center;">
    <a href="{{ portalUrl }}" class="button" style="background-color: #6b7280;">Můj účet</a>
</div>
```

The existing CSS `.button` class works as-is; the second button uses an inline gray override to visually de-emphasize.

### 6. New documentation file `.claude/CUSTOMER_DOCUMENTS.md`

Single canonical reference. Sections:

```markdown
# Customer-facing documents

This is the index of every document a customer can encounter during the order
lifecycle: what it is, when it is generated/sent, where it lives in the codebase,
and how the customer accesses it.

## Inventory

| # | Document | Generator / Source | Storage location | Customer access |
|---|---|---|---|---|
| 1 | Faktura (PDF) | Fakturoid → InvoicingService | absolute path on disk via Invoice.pdfPath | InvoicePdfController (auth) + linked from order_documents partial |
| 2 | Smlouva (DOCX) | ContractDocumentGenerator | var/contracts/contract_{uuid}_{date}.docx | ContractDownloadController (auth) + linked from order_documents partial |
| 3 | Smlouva (PDF) | DocumentPdfConverter (on-the-fly from DOCX) | not persisted | ContractPdfController (auth) + linked from order_documents partial |
| 4 | Mapa skladu (PNG, vyznačená jednotka) | StorageMapImageGenerator::generate(Storage) | not persisted | OrderMapImageController (UUID-gated, COMPLETED only) + attached to order_confirmation email |
| 5 | VOP (PDF) | static asset | public/documents/vop.pdf | direct URL |
| 6 | Poučení spotřebitele (PDF) | static asset | public/documents/pouceni-spotrebitele.pdf | direct URL |
| 7 | Podmínky opakovaných plateb (PDF) | static asset | public/documents/podminky-opakovanych-plateb.pdf | direct URL — only relevant for recurring rentals |
| 8 | Provozní řád pobočky (PDF/DOCX) | per-place upload | public/uploads/{Place.operatingRulesPath} | upload_url helper — only when place has one |
| 9 | Formulář odstoupení od smlouvy (DOCX) | static asset | public/documents/formular-odstoupeni-od-smlouvy.docx | direct URL |
| 10 | Reklamační formulář (DOCX) | static asset | public/documents/reklamacni-formular.docx | direct URL |

## Email touchpoints

| Email | Trigger | Attachments | Links to docs |
|---|---|---|---|
| order_confirmation | OrderCreated (pre-payment) | map PNG + VOP + consumer notice + recurring terms (if applicable) + operating rules (if any) | manageUrl |
| invoice | InvoiceCreated | invoice PDF | — |
| contract_ready | OrderCompleted (post-payment) | contract DOCX + operating rules | documentsUrl (success page) + portalUrl |

## Where the customer sees the full list

Two places render the same `templates/components/order_documents.html.twig`
partial with the same data shape:

- `templates/public/order_complete.html.twig` — unauthenticated success page
  reachable at /objednavka/{id}/dokonceno after payment.
- `templates/portal/user/order/detail.html.twig` — authenticated portal page
  for any of the customer's orders.

## Adding a new document type

1. Decide whether it's static (drop into `public/documents/`) or generated.
2. If generated, build a service in `src/Service/` and a route in
   `src/Controller/Public/` (or Portal/User if it requires auth).
3. Add a new row to the partial, gated on the right condition.
4. Update this file's inventory table.
5. Decide if the email should attach it or just link to the documents page.
```

This file is the canonical answer to "what documents do we have?" — future devs grep it before adding new ones.

### 7. Tests

- Unit/integration test `tests/Integration/Controller/Public/OrderMapImageControllerTest.php`:
  - COMPLETED order with mapped place → 200 + `Content-Type: image/png` + non-empty body.
  - Order in any non-COMPLETED status → 404.
  - Place with `mapImagePath = null` → 404.
  - Invalid UUID → 404.
- Smoke test `tests/Integration/Controller/Public/OrderCompleteControllerTest.php` (or extend if exists): assert the rendered HTML contains the strings "Vaše dokumenty", "VOP", "Poučení spotřebitele", and a link to `/documents/vop.pdf`. For a recurring order, assert "Podmínky opakovaných plateb" is in the output. For a non-recurring order, assert that string is **not** present.
- Smoke for `SendContractReadyEmailHandler` — assert the rendered email body contains a link with the success-page URL ending in `/dokonceno#dokumenty`.
- No new tests needed for the `templates/portal/user/order/detail.html.twig` swap — existing tests for that page should still pass; if any asserts on the exact HTML structure of the contract/invoice section, update or relax those.

## Acceptance

- `docker compose exec web composer quality` is green.
- After paying for an order in the dev fixtures (use `db:reset`, walk a guest checkout via `tenant@example.com` flow), the success page at `/objednavka/{id}/dokonceno` shows a "Vaše dokumenty" card with:
  - Smlouva PDF + DOCX buttons (linking to portal_user_contract_pdf / _download)
  - Faktura PDF button
  - Mapa skladu (PNG) — clicking downloads `mapa-skladu.png`
  - VOP, Poučení spotřebitele links opening in new tabs to `/documents/vop.pdf` and `/documents/pouceni-spotrebitele.pdf`
  - Formulář odstoupení + Reklamační formulář download links
  - For a fixture place that has operating rules: Provozní řád link present
  - For a recurring (unlimited) order: Podmínky opakovaných plateb link present
  - For a limited-term order: that link is absent
- The same "Vaše dokumenty" card renders identically on `/portal/objednavky/{id}` for the order's owner.
- After `OrderCompleted`, the `contract_ready` email body contains a "Stáhnout všechny dokumenty" button linking to `/objednavka/{id}/dokonceno#dokumenty` (verify with `mailpit` / dev `MAILER_DSN=null` log).
- Clicking the email button lands on the success page with the documents card scrolled into view (anchor works).
- `GET /objednavka/{id}/mapa.png` for a completed order returns 200 + `image/png`; for a non-completed order returns 404.
- `.claude/CUSTOMER_DOCUMENTS.md` exists, includes the full inventory + email-touchpoints tables.

## Out of scope

- **Authenticated-only gating on `/objednavka/{id}/...` UUID routes.** This spec keeps the existing access model (anyone with the UUID can hit the success page after payment) — same as the rest of the public order family. Locking it down is a separate security review across the whole route group.
- **Polishing the unauth UX for portal-only buttons.** When a not-logged-in user clicks "Smlouva PDF" on the success page, they get redirected to login by the firewall. We don't add a friendly "log in to download" inline state; the firewall's flash + redirect is acceptable for now.
- **Replacing the portal_user_invoice_pdf / portal_user_contract_* routes** with public, UUID-gated equivalents so the success page works fully without login. Bigger change with security implications — separate spec if desired.
- **Adding the invoice PDF / map PNG / VOP as attachments to `contract_ready` email.** Would push email size past 5 MB easily; the link approach is intentional. Invoice keeps its own dedicated email (`SendInvoiceEmailHandler`).
- **Server-side rendering of the contract preview as PDF on payment success.** The on-demand `DocumentPdfConverter` route already exists; we link to it, not duplicate it.
- **Invalidating / regenerating the highlighted map PNG when storages move on the canvas.** The image is regenerated on every fetch from current `Storage.coordinates`, so it's always current.
- **A dedicated `/portal/objednavky/{id}/dokumenty` page.** Inline rendering on the order detail + on the success page is sufficient and avoids navigation churn.
- **Withdrawal / complaint form-filling UX** (interactive form vs. static template). Today the static DOCX is what we have; replacing it is a separate product question.
- **Per-language document variants.** The site is Czech-only; if/when we add other languages, this partial will need a language switch.

## Open questions

None — proceed.
