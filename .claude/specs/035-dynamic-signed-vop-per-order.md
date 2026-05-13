# 035 — Dynamic, per-order signed VOP (DOCX template + ${PRICELIST_URL} + signature stamping)

**Status:** done
**Type:** feature (compliance + customer experience)
**Scope:** medium (~10 files: 2 new services, 2 new controllers, 1 generator extension, 2 template edits, 1 email handler edit, 2 unit tests, 1 docker note)
**Depends on:** none

## Problem

VOP today is a single static PDF (`public/documents/vop.pdf`) shipped to every customer regardless of which place they ordered. Two issues with that:

1. **Operator can't edit easily.** Every wording change requires re-exporting a PDF and committing it. The legal team works in Google Docs; the round-trip via PDF is painful and error-prone.
2. **No personalisation, no signature.** The current PDF references "Ceník" with a hardcoded marketing URL `https://www.fajnesklady.cz`. With multi-place tenancy, the relevant Ceník is per-place — `/pobocka/{placeId}`. There is also no evidence that the customer signed *the body of the VOP itself* — the only signature exists on the standalone contract document, which weakens evidentiary value of the standard terms (OZ § 1751: standard terms must be communicated and made part of the contract).

## Goal

VOP becomes a per-order document, generated at order placement from a DOCX template the operator maintains in Google Docs:

- `${PRICELIST_URL}` placeholder is replaced with the absolute URL of the per-place pricelist page (`public_place_pricelist` → `/pobocka/{id}/cenik`).
- `${OPERATING_RULES_URL}` placeholder is replaced with the absolute URL of the place's uploaded operating rules file (`Place.operatingRulesPath`, served from `/uploads/...`). When the place has no operating rules uploaded, falls back to the place detail page URL (`public_place_detail` → `/pobocka/{id}`) so the link never 404s.
- The customer's drawn signature (PNG already captured on `Order.signaturePath`) is stamped at the bottom-right corner of every body page (pages 1–9).
- The two fillable form annexes at the end (Příloha č. 1 — Vzorový formulář pro odstoupení; reklamační formulář — pages 10–11) carry **no** signature, because the customer fills *those* in if they later want to withdraw or complain.
- The rendered DOCX is persisted to `var/vop/` (mirrors how contracts are persisted) so the exact version the customer signed is recoverable years later. PDF is rendered on-demand from that DOCX.

The file the customer downloads from the order-docs hub and the file attached to the order-confirmation email both come from the same generator. The legacy static `public/documents/vop.pdf` is removed (compliance drift risk).

Out of scope (explicit): no changes to the on-page legal modals at `templates/public/_terms_and_conditions_content.html.twig` (the in-flow consent modal during order acceptance) — those keep their existing Twig content. See "Out of scope" below.

## Context (current state)

### Static VOP today

- File: `public/documents/vop.pdf`. Single canonical PDF shipped to every order.
- Linked from `templates/components/order_documents.html.twig:99` (portal) and `templates/components/order_status_documents.html.twig:86` (public `/stav` permalink).
- Attached as `vop.pdf` (`application/pdf`) by `src/Event/SendOrderConfirmationEmailHandler.php:84-88`.
- Existing unit test `tests/Unit/Event/SendOrderConfirmationEmailHandlerTest.php:38,55,67` writes a stub PDF and asserts the attachment name.

### Building blocks already in the codebase

- **DOCX placeholder substitution**: `App\Service\ContractDocumentGenerator` (`src/Service/ContractDocumentGenerator.php:111-141`) uses `PhpOffice\PhpWord\TemplateProcessor`. `TemplateProcessor` substitutes `${TOKEN}` placeholders in `word/document.xml` *and* in any `word/header*.xml` / `word/footer*.xml` part. We reuse this library directly.
- **DOCX → PDF**: `App\Service\DocumentPdfConverter` (`src/Service/DocumentPdfConverter.php`) shells out to LibreOffice (`soffice --headless --convert-to pdf`). Already wired into the contract download path. Reused as-is.
- **Signature storage**: `App\Service\SignatureStorage` writes the PNG to `var/signatures/signature_{orderId}.png`. The path is stored on `Order.signaturePath` (nullable). Same property used by `ContractDocumentGenerator::renderBytesForOrder()` (`src/Service/ContractDocumentGenerator.php:87`).
- **Public absolute URLs from messenger context**: `App\Service\OrderStatusUrlGenerator` (`src/Service/OrderStatusUrlGenerator.php:32-39`) confirms `UrlGeneratorInterface::ABSOLUTE_URL` works in event-handler context — `framework.router.default_uri` is configured. The `public_place_pricelist` route at `/pobocka/{id}/cenik` already exists (`src/Controller/Public/PlacePricelistController.php:17`) — that controller renders the per-place price list (storage types, availability, price ranges) and is the natural target for `${PRICELIST_URL}`.
- **Per-place operating rules URL**: `Place.operatingRulesPath` (nullable string) holds a relative path under `public/uploads/` (e.g. `places/{placeId}/operating-rules/provozni-rad.pdf`). The existing Twig helper `App\Twig\UploadExtension::getUploadUrl()` (`src/Twig/UploadExtension.php:32`) prepends `/uploads/` and runs it through `Symfony\Component\Asset\Packages::getUrl()` — but it returns a **relative** URL. For VOP we need the **absolute** form. Use `Symfony\Component\HttpFoundation\UrlHelper` injected into the generator: `$urlHelper->getAbsoluteUrl('/uploads/'.$place->operatingRulesPath)`. `UrlHelper` falls back to the router's `RequestContext` when there's no active HTTP request, so it works from messenger handlers and CLI alike.
- **Place detail route for fallback**: `public_place_detail` at `/pobocka/{id}` (`src/Controller/Public/PlaceDetailController.php:17`) — used when the place has no operating rules yet so the placeholder never resolves to a broken link.
- **Signed-permalink download pattern**: `App\Controller\Public\OrderContractDownloadController` (`src/Controller/Public/OrderContractDownloadController.php`) is the template — `UriSigner::checkRequest`, `realpath()` + prefix guard, `BinaryFileResponse` with `DISPOSITION_ATTACHMENT`. We mirror this for the public VOP route.
- **Portal-auth download pattern**: `App\Controller\Portal\User\ContractPdfController` is the template for the portal route — `OrderVoter::VIEW` for VOP (the relevant entity is `Order`, not `Contract`).

### Verified findings against the operator's example DOCX

I converted `/Users/janmikes/Downloads/VOP - FINAL - 25-4-25.docx` (the actual current VOP) through the existing pipeline (`docker compose exec web soffice --convert-to pdf`) and inspected the result with `pdfinfo` / `pdftotext -layout`:

- **11 pages total.** Pages 1–8: body. Page 9: chapter XV (sdělení spotřebitelům) + chapter XVI (závěrečná) + the *announcement* line "Příloha č. 1" at the bottom. **Pages 10–11: the two fillable form annexes** (withdrawal form, complaint form — both addressed to "Mekmann s.r.o., …").
- → `skipLastPages = 2` is the correct default. Signature appears on pages 1–9; pages 10–11 stay clean.
- LibreOffice 25.2 emits **PDF 1.7**. The free `setasign/fpdi` reads only ≤ PDF 1.4. → We pipe LibreOffice output through **Ghostscript** (already installed in the web container — verified via `apt list --installed | grep ghostscript` → `ghostscript/now 10.05.1`) to downgrade to PDF 1.4 *only when stamping is required*. Ghostscript is not added; it's already there.
- The DOCX uses a "different first page" footer toggle but only one section. This is exactly why we did **not** go the section-break-footer route — it would have required restructuring the operator's document.

### Composer / config wiring referenced below

- `composer.json` already has `tecnickcom/tcpdf:^6.5` (verified in `composer.lock`). We add `setasign/fpdi:^2` and `setasign/fpdi-tcpdf:^2`. Both MIT.
- Service wiring lives in `config/services.php`. Existing entries to mirror:
  - `App\Service\ContractDocumentGenerator` (`config/services.php:95-99`) — `$contractsDirectory` argument.
  - `App\Event\SendOrderConfirmationEmailHandler` (`config/services.php:110-115`) — `$projectDir` + `$contractTemplatePath` arguments.

## Architecture

```
OrderPlaced event
   │
   ├─► SendOrderConfirmationEmailHandler
   │      │
   │      ├─► VopDocumentGenerator::generate(order, templatePath)
   │      │       • Substitute ${PRICELIST_URL} via TemplateProcessor
   │      │       • Persist as var/vop/vop_{orderId}.docx
   │      │
   │      └─► VopPdfStamper::stampSignedPdfBytes(docxPath, order.signaturePath)
   │              • DocumentPdfConverter::convertToPdf(docxPath)  → PDF 1.7 file
   │              • If signaturePath !== null:
   │                  · gs -dCompatibilityLevel=1.4 → PDF 1.4 bytes
   │                  · FPDI+TCPDF: copy each page; stamp signature
   │                    on pages 1..(N - skipLastPages) at bottom-right
   │              • Else: return original PDF bytes unchanged
   │              ↓
   │          email->attach(bytes, 'vop-{shortId}.pdf', 'application/pdf')
   │
On-demand download (portal or public permalink)
   ├─► VopPdfController             [/portal/objednavky/{id}/vop.pdf]   (OrderVoter::VIEW)
   └─► OrderVopDownloadController   [/objednavka/{id}/dokumenty/vop.pdf] (UriSigner)
         Both load var/vop/vop_{orderId}.docx → VopPdfStamper → BinaryFileResponse
```

VOP DOCX path is **deterministic from order id** (`vop_{order.id.toRfc4122()}.docx`) — no entity column needed. Path is computed by `VopDocumentGenerator::pathFor(Order)`, used by both the generator (write) and the controllers (read).

## Requirements

### 1. Composer deps

In the project root:

```bash
docker compose exec web composer require setasign/fpdi:^2 setasign/fpdi-tcpdf:^2
```

Both are MIT-licensed and pure-PHP. No additional system packages required (Ghostscript is already in the container; verified above).

### 2. New service: `App\Service\Vop\VopDocumentGenerator`

`src/Service/Vop/VopDocumentGenerator.php`

Responsibilities: render the VOP DOCX for an order (substituting `${PRICELIST_URL}` only) and persist to disk.

```php
final readonly class VopDocumentGenerator
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private UrlHelper $urlHelper,                  // Symfony\Component\HttpFoundation\UrlHelper
        private string $vopDocumentsDirectory,         // %kernel.project_dir%/var/vop
    ) {}

    public function generate(Order $order, string $templatePath): string
    {
        if (!file_exists($templatePath)) {
            throw new \RuntimeException(sprintf('VOP template not found: %s', $templatePath));
        }

        $place = $order->storage->getPlace();
        $placeId = $place->id->toRfc4122();

        $processor = new \PhpOffice\PhpWord\TemplateProcessor($templatePath);
        $processor->setValue('PRICELIST_URL', $this->urlGenerator->generate(
            'public_place_pricelist',
            ['id' => $placeId],
            UrlGeneratorInterface::ABSOLUTE_URL,
        ));
        $processor->setValue('OPERATING_RULES_URL', $this->resolveOperatingRulesUrl($place));

        if (!is_dir($this->vopDocumentsDirectory)) {
            mkdir($this->vopDocumentsDirectory, 0755, true);
        }

        $outputPath = $this->pathFor($order);
        $processor->saveAs($outputPath);

        return $outputPath;
    }

    public function pathFor(Order $order): string
    {
        return sprintf('%s/vop_%s.docx', $this->vopDocumentsDirectory, $order->id->toRfc4122());
    }

    private function resolveOperatingRulesUrl(Place $place): string
    {
        if (null !== $place->operatingRulesPath && '' !== $place->operatingRulesPath) {
            // Absolute URL to the uploaded file under public/uploads/.
            return $this->urlHelper->getAbsoluteUrl('/uploads/'.$place->operatingRulesPath);
        }

        // Fallback when the place hasn't uploaded operating rules yet — link to the
        // place detail page so the customer at least lands somewhere relevant.
        return $this->urlGenerator->generate(
            'public_place_detail',
            ['id' => $place->id->toRfc4122()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }
}
```

Notes:
- Two URL placeholders. The operator confirmed the DOCX placeholders are "just the URLs and the signatures" — and the signature is post-PDF, not in the DOCX. If new URL placeholders are added later, extend here.
- `UrlGeneratorInterface::ABSOLUTE_URL` is fine in the event-handler / messenger context — `OrderStatusUrlGenerator` already proves this works.
- `UrlHelper::getAbsoluteUrl()` is the correct way to build an absolute URL for an asset path (uploaded file). It uses `RequestContext` as a fallback when no HTTP request is active, so it works from `OrderPlaced` event handlers and CLI alike.
- `public_place_pricelist` and `public_place_detail` are unsigned public pages — no `UriSigner` involvement.
- The routes use `id.toRfc4122()` (consistent with the controllers' `requirements: ['id' => '[0-9a-f-]{36}']` and the rest of the codebase, not `toBase32`).

### 3. New service: `App\Service\Vop\VopPdfStamper`

`src/Service/Vop/VopPdfStamper.php`

Responsibilities: convert DOCX → PDF, downgrade to PDF 1.4 via Ghostscript (only when stamping), stamp signature on pages `1..(totalPages - skipLastPages)` at bottom-right via FPDI + TCPDF. Returns final PDF bytes.

```php
final readonly class VopPdfStamper
{
    public function __construct(
        private DocumentPdfConverter $pdfConverter,
        private LoggerInterface $logger,
        private int $skipLastPages,        // 2 — annex pages (withdrawal + complaint)
        private int $signatureWidthMm,     // 50
        private int $signatureMarginMm,    // 12 — distance from right and bottom edges
    ) {}

    /**
     * @return string|null PDF bytes, or null if conversion failed
     */
    public function stampSignedPdfBytes(string $docxPath, ?string $signaturePath): ?string
    {
        $pdfPath = $this->pdfConverter->convertToPdf($docxPath);
        if (null === $pdfPath) {
            return null;
        }

        if (null === $signaturePath || !file_exists($signaturePath)) {
            // No signature → ship the unsigned LibreOffice output as-is.
            $bytes = file_get_contents($pdfPath);
            return false === $bytes ? null : $bytes;
        }

        // FPDI free supports only PDF ≤ 1.4. LibreOffice emits PDF 1.7.
        // Ghostscript (already in the container) downgrades cleanly.
        $pdf14Path = $this->downgradeToPdf14($pdfPath);
        if (null === $pdf14Path) {
            return null;
        }

        try {
            return $this->stampPdf($pdf14Path, $signaturePath);
        } finally {
            @unlink($pdf14Path);
        }
    }

    private function downgradeToPdf14(string $pdfPath): ?string
    {
        $outPath = tempnam(sys_get_temp_dir(), 'vop_v14_').'.pdf';

        $process = new \Symfony\Component\Process\Process([
            'gs', '-sDEVICE=pdfwrite', '-dCompatibilityLevel=1.4',
            '-dPDFSETTINGS=/default', '-dNOPAUSE', '-dQUIET', '-dBATCH',
            '-sOutputFile='.$outPath, $pdfPath,
        ]);
        $process->setTimeout(60);

        try {
            $process->run();
        } catch (\Throwable $e) {
            $this->logger->error('VOP: Ghostscript downgrade failed', ['exception' => $e]);
            return null;
        }

        if (!$process->isSuccessful() || !file_exists($outPath)) {
            $this->logger->error('VOP: Ghostscript downgrade non-zero exit', [
                'stderr' => $process->getErrorOutput(),
            ]);
            return null;
        }

        return $outPath;
    }

    private function stampPdf(string $pdfPath, string $signaturePath): string
    {
        $pdf = new \setasign\Fpdi\Tcpdf\Fpdi();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->setAutoPageBreak(false, 0);

        $pageCount = $pdf->setSourceFile($pdfPath);
        $stampUntil = max(0, $pageCount - $this->skipLastPages);

        for ($n = 1; $n <= $pageCount; ++$n) {
            $tplId = $pdf->importPage($n);
            $size = $pdf->getTemplateSize($tplId);
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($tplId);

            if ($n <= $stampUntil) {
                // Bottom-right: x = pageWidth - margin - signatureWidth, y = pageHeight - margin - signatureHeight
                // Image height is auto-derived from PNG aspect ratio (TCPDF: pass 0 for auto height).
                $x = $size['width'] - $this->signatureMarginMm - $this->signatureWidthMm;
                $y = $size['height'] - $this->signatureMarginMm - 18; // ~18 mm reserved; PNG is auto-scaled
                $pdf->Image(
                    file: $signaturePath,
                    x: $x,
                    y: $y,
                    w: $this->signatureWidthMm,
                    h: 0,             // auto height preserves aspect ratio
                    type: 'PNG',
                );
            }
        }

        return $pdf->Output('', 'S');
    }
}
```

Notes:
- `Fpdi\Tcpdf\Fpdi` is provided by `setasign/fpdi-tcpdf`. TCPDF was already installed; the bridge gives us page import + image stamping in one object.
- `setAutoPageBreak(false, 0)` is critical — TCPDF would otherwise clip the signature image and silently spill onto a new page when stamping near the bottom margin.
- The "18 mm reserved height" is a conservative placeholder; the real height is auto-derived by TCPDF from the PNG aspect ratio because we pass `h: 0`. The Y subtraction should keep the image inside the page even for tall signatures (most signatures are ~3:1 wide so ~17 mm tall at 50 mm wide).
- Returns the PDF as bytes (`Output('', 'S')`). Caller decides whether to attach to email or stream as response.

### 4. Service wiring (`config/services.php`)

Add to the `$container->services()` block, alongside the existing `App\Service\ContractDocumentGenerator` entry:

```php
'App\\Service\\Vop\\VopDocumentGenerator' => [
    'arguments' => [
        '$vopDocumentsDirectory' => '%kernel.project_dir%/var/vop',
    ],
],
'App\\Service\\Vop\\VopPdfStamper' => [
    'arguments' => [
        '$skipLastPages'      => 2,    // pages 10-11 of the current 11-page VOP are the form annexes
        '$signatureWidthMm'   => 50,
        '$signatureMarginMm'  => 12,
    ],
],
```

Extend the existing `App\Event\SendOrderConfirmationEmailHandler` entry to add:

```php
'$vopTemplatePath' => '%kernel.project_dir%/templates/documents/vop_template.docx',
```

(Keep the existing `$projectDir` and `$contractTemplatePath` arguments — `$projectDir` is no longer used after the change in §6 below; remove it from both the argument list AND the constructor.)

### 5. Template DOCX file

The operator places the DOCX at:

```
templates/documents/vop_template.docx
```

The template is the operator's existing `VOP - FINAL - 25-4-25.docx` with **two** edits:

- In chapter II ("Definice"), replace the literal URL `https://www.fajnesklady.cz` (in the Ceník definition line) with the placeholder `${PRICELIST_URL}`.
- In chapter II ("Definice"), in the "Provozní řád" definition line, insert the placeholder `${OPERATING_RULES_URL}` where the URL of the operating rules document should appear. (Currently the definition reads "...zveřejněný Pronajímatelem a dostupný z..." with no concrete URL — the operator adds the URL placeholder there.)

Both placeholders are case-sensitive plain text. Keep the surrounding hyperlink formatting if Google Docs preserves it; if hyperlink is lost in the substitution, the URL will still render as plain text, which most PDF viewers auto-detect. The `${TOKEN}` syntax is what `PhpOffice\PhpWord\TemplateProcessor` expects.

No section breaks, no footer markers. The DOCX stays a single section — the signature stamping is handled post-PDF.

### 6. Wire VOP into `SendOrderConfirmationEmailHandler`

`src/Event/SendOrderConfirmationEmailHandler.php`

Replace the existing static-VOP block (lines 84-88, currently `Attach VOP` comment + `attachFromPath(.../public/documents/vop.pdf)`) with dynamic generation.

Changes:

1. Constructor: drop `private string $projectDir`, add `private VopDocumentGenerator $vopGenerator`, `private VopPdfStamper $vopStamper`, `private string $vopTemplatePath`. (`$projectDir` was only used to build the static doc paths; the consumer-notice and recurring-payments static paths still use it, so **keep `$projectDir`** but remove the VOP-specific block. Re-check after edit — `$projectDir` is still referenced at lines 91-101 for the other two static docs.)
2. Replace the lines 84-88 block with:

   ```php
   // Generate per-order VOP (DOCX → PDF → signature stamp), attach as PDF.
   $vopDocxPath = $this->vopGenerator->generate($order, $this->vopTemplatePath);
   $vopPdfBytes = $this->vopStamper->stampSignedPdfBytes($vopDocxPath, $order->signaturePath);
   if (null !== $vopPdfBytes) {
       $email->attach(
           $vopPdfBytes,
           sprintf('vop-%s.pdf', substr($order->id->toRfc4122(), 0, 8)),
           'application/pdf',
       );
   }
   ```

Naming the attachment `vop-{shortId}.pdf` mirrors how the contract attachment is named (`smlouva-{shortId}.pdf` in lines 70-74).

### 7. New controller: portal download

`src/Controller/Portal/User/VopPdfController.php`

Mirror `ContractPdfController` exactly, but for orders + VOP:

```php
#[Route('/portal/objednavky/{id}/vop.pdf', name: 'portal_user_order_vop_pdf')]
#[IsGranted('ROLE_USER')]
final class VopPdfController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly VopDocumentGenerator $vopGenerator,
        private readonly VopPdfStamper $vopStamper,
        #[Autowire('%kernel.project_dir%/templates/documents/vop_template.docx')]
        private readonly string $vopTemplatePath,
        #[Autowire('%kernel.project_dir%/var/vop')]
        private readonly string $vopDirectory,
    ) {}

    public function __invoke(string $id): Response
    {
        if (!Uuid::isValid($id)) {
            throw new NotFoundHttpException('Objednávka nenalezena.');
        }

        try {
            $order = $this->orderRepository->get(Uuid::fromString($id));
        } catch (\Throwable) {
            throw new NotFoundHttpException('Objednávka nenalezena.');
        }

        $this->denyAccessUnlessGranted(OrderVoter::VIEW, $order);

        $docxPath = $this->ensureVopExists($order);
        $pdfBytes = $this->vopStamper->stampSignedPdfBytes($docxPath, $order->signaturePath);
        if (null === $pdfBytes) {
            throw new NotFoundHttpException('VOP PDF se nepodařilo vygenerovat.');
        }

        return new Response(
            $pdfBytes,
            Response::HTTP_OK,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => sprintf(
                    'attachment; filename="vop-%s.pdf"',
                    substr($order->id->toRfc4122(), 0, 8),
                ),
            ],
        );
    }

    private function ensureVopExists(Order $order): string
    {
        $path = $this->vopGenerator->pathFor($order);
        // Path-traversal guard: resolved path must live inside vopDirectory.
        $realDir = realpath($this->vopDirectory) ?: $this->vopDirectory;
        if (!file_exists($path)) {
            // Late-generate for orders predating this feature.
            return $this->vopGenerator->generate($order, $this->vopTemplatePath);
        }
        $real = realpath($path);
        if (false === $real || !str_starts_with($real, $realDir.'/')) {
            throw new NotFoundHttpException('VOP nenalezeno.');
        }
        return $path;
    }
}
```

The "late-generate for orders predating this feature" path is intentional: when this ships, existing pre-feature orders won't have a `var/vop/vop_*.docx` file. First click triggers generation. (This is fine because the substitution is deterministic and doesn't depend on signing-time state — `${PRICELIST_URL}` is computed from the order's place at request time.)

### 8. New controller: public signed-permalink download

`src/Controller/Public/OrderVopDownloadController.php`

Mirror `OrderContractDownloadController` (`src/Controller/Public/OrderContractDownloadController.php`) — same `UriSigner::checkRequest` gate, same `realpath()` guard, same `BinaryFileResponse` pattern. Differences from the contract controller:

- Route: `/objednavka/{id}/dokumenty/vop.pdf`, name `public_order_vop_download`, requirements `id => '[0-9a-f-]{36}'`.
- **No `OrderStatus::COMPLETED` filter.** Unlike contracts (which only exist post-payment), VOP is relevant from the moment the order is placed — the order-confirmation email goes out *pre*-payment with VOP attached, and the customer may want to refer back to it via the permalink before paying. Just check `null !== $order && OrderStatus::CANCELED !== $order->status`.
- Reuses the same `ensureVopExists()` helper logic; can be inlined or factored. To avoid a shared trait, inline it (it's ~10 lines).
- Returns a `Response` with PDF bytes inline (same as the portal controller above) — the file lives in memory after stamping; we don't write it to disk between conversion and response.

### 9. Mint signed VOP URL: extend `OrderStatusUrlGenerator`

`src/Service/OrderStatusUrlGenerator.php`

Add a method mirroring `generateContractDownload()`:

```php
public function generateVopDownload(Order $order): string
{
    $url = $this->urlGenerator->generate(
        'public_order_vop_download',
        ['id' => $order->id->toRfc4122()],
        UrlGeneratorInterface::ABSOLUTE_URL,
    );

    return $this->uriSigner->sign($url);
}
```

The class doc-comment already says "Twig should never call `path()` for them directly" — this method is the only legitimate way to mint the URL.

### 10. Replace static VOP link in the docs panels

`templates/components/order_documents.html.twig` (line 99) — currently:

```twig
{{ _self.documentRow(
    asset('documents/vop.pdf'),
    'Všeobecné obchodní podmínky (VOP)',
    'Podmínky platné v době uzavření smlouvy',
    'pdf'
) }}
```

Change to:

```twig
{{ _self.documentRow(
    path('portal_user_order_vop_pdf', {id: order.id}),
    'Všeobecné obchodní podmínky (VOP)',
    'Podpis na každé straně, platné v době uzavření smlouvy',
    'pdf'
) }}
```

The portal partial is invoked from a logged-in context (see file's docblock at lines 12-14), so `path('portal_user_order_vop_pdf', …)` is safe.

`templates/components/order_status_documents.html.twig` (line 86) — currently uses `asset('documents/vop.pdf')`. This partial is rendered for the public permalink and gets its other doc URLs pre-signed by `OrderStatusUrlGenerator`. Change to a passed-in URL:

- The `order_status_documents` partial is rendered by `OrderStatusController` (and/or its template). Find where the partial is `include`d and add a `vopDownloadUrl: orderStatusUrlGenerator.generateVopDownload(order)` to the include context. (The controller already passes `contractDownloadUrl`, `mapDownloadUrl`, etc. — follow the same wiring.)
- In the template body, replace `asset('documents/vop.pdf')` with `vopDownloadUrl`.

Subtitle: same update — "Podpis na každé straně, platné v době uzavření smlouvy" — small, customer-noticeable signal that this is an order-specific document.

### 11. Remove the static VOP file

```bash
git rm public/documents/vop.pdf
```

Once dynamic VOP ships, the static file is a compliance-drift hazard (operator updates DOCX, forgets to re-export PDF, two versions of "the" VOP exist). Removing it forces all consumers to go through the new pipeline.

### 12. Update the customer-documents inventory

`.claude/CUSTOMER_DOCUMENTS.md`

Update row 5:

| 5 | VOP (PDF, per-order, signed) | `VopDocumentGenerator` + `VopPdfStamper` | DOCX persisted at `var/vop/vop_{orderId}.docx`; PDF rendered on demand | `OrderVopDownloadController` (public, UriSigner-gated) + `VopPdfController` (auth, `OrderVoter::VIEW`) |

Add a one-line note in the "Where the customer sees the full list" section: "VOP is now per-order and bears the customer's signature on every body page; form annexes (withdrawal, complaint) intentionally remain unsigned."

### 13. Tests

Two new unit tests + one update to the existing handler test:

**`tests/Unit/Service/Vop/VopDocumentGeneratorTest.php`** (new):

- Build a tiny inline DOCX template containing both `${PRICELIST_URL}` and `${OPERATING_RULES_URL}` (use `PhpOffice\PhpWord\PhpWord` to generate it inside the test, save to a temp path).
- Stub `UrlGeneratorInterface::generate` to return `'https://test.example/pobocka/AAA/cenik'` for `public_place_pricelist`, `'https://test.example/pobocka/AAA'` for `public_place_detail`. Stub `UrlHelper::getAbsoluteUrl` to prefix `'https://test.example'`.
- Two cases:
  - Place WITH operating rules (`operatingRulesPath = 'places/foo/operating-rules/x.pdf'`): assert rendered DOCX contains `https://test.example/uploads/places/foo/operating-rules/x.pdf`.
  - Place WITHOUT operating rules (`operatingRulesPath = null`): assert rendered DOCX contains the place detail URL fallback `https://test.example/pobocka/AAA`.
- In both cases assert the placeholder literals (`${PRICELIST_URL}`, `${OPERATING_RULES_URL}`) are gone from the document XML.

**`tests/Unit/Service/Vop/VopPdfStamperTest.php`** (new):

Pragmatic: this exercises real LibreOffice + GS + FPDI. Ship as an integration-style test under `tests/Unit/` only if it reliably passes inside the docker test runner; otherwise, mark it `@group integration` and put it under `tests/Integration/Service/Vop/`.

- Use a real fixture DOCX (e.g. `tests/fixtures/vop/vop_4page_fixture.docx` — committed binary, ~3 pages of placeholder text — small).
- Use a real signature PNG (e.g. `tests/fixtures/signature_sample.png` — a tiny black-on-white test scribble, committed).
- Call `stampSignedPdfBytes($docxPath, $signaturePath)` with `skipLastPages = 1`.
- Assert: returned bytes start with `%PDF-`, a fresh `pdfinfo` (via Process) reports the same page count as the input, and a `pdftotext` over the result still contains the body text (sanity — the pages weren't corrupted).
- Visual correctness of stamp position is verified manually in the acceptance step below; not test-automated (low ROI vs maintenance cost).

**`tests/Unit/Event/SendOrderConfirmationEmailHandlerTest.php`** (update):

- Drop the `file_put_contents($this->tempDir.'/public/documents/vop.pdf', '%PDF-vop')` line (38).
- Stub the new `VopDocumentGenerator` and `VopPdfStamper` (real construction is too heavy here).
- The stub `VopPdfStamper::stampSignedPdfBytes` returns `'%PDF-vop'`.
- Existing assertion `$this->assertContains('vop.pdf', $names)` becomes `$this->assertNotEmpty(array_filter($names, fn ($n) => str_starts_with($n, 'vop-')))` (filename gets the order short-id suffix now).

### 14. Documentation note in CLAUDE.md

Add a short paragraph under the "Customer-facing documents" link or as a new bullet in the "Compliance ruleset" section:

> **VOP is dynamic.** The legal master is `templates/documents/vop_template.docx`. The operator edits in Google Docs and exports to that path. Supported placeholders: `${PRICELIST_URL}` (resolves to `/pobocka/{id}/cenik`) and `${OPERATING_RULES_URL}` (resolves to the place's uploaded operating rules; falls back to the place detail page when none uploaded). Customer signature is stamped on body pages; the last 2 pages (form annexes) stay unsigned. If the annex count changes, update `VopPdfStamper`'s `$skipLastPages` argument in `config/services.php`.

## Acceptance

- [ ] `composer require setasign/fpdi:^2 setasign/fpdi-tcpdf:^2` succeeds; `composer.lock` updated.
- [ ] `templates/documents/vop_template.docx` is in the repo (operator commits it; this spec doesn't generate it).
- [ ] `public/documents/vop.pdf` is removed; no references to it remain (`grep -rn 'documents/vop\.pdf'` returns nothing).
- [ ] `VopDocumentGenerator::generate(...)` for a fixture order produces a DOCX at `var/vop/vop_{orderId}.docx` whose content contains the per-place pricelist URL AND the per-place operating-rules URL (or fallback to place detail URL when none uploaded), and no `${PRICELIST_URL}` / `${OPERATING_RULES_URL}` literal remains.
- [ ] `VopPdfStamper::stampSignedPdfBytes(...)` for a signed order produces a valid PDF whose page count equals the source DOCX page count, and the signature image is present on the first `(N - 2)` pages but absent on the last 2 (visual check using e.g. `pdftoppm` + image grep, or just open in Preview).
- [ ] Order-confirmation email for a freshly signed order has `vop-XXXXXXXX.pdf` attached; opening the attachment shows the per-place URL on the Definice page and the signature on body pages.
- [ ] Logged-in customer can download VOP from `/portal/objednavky/{id}/vop.pdf`; another user gets 403; admin/landlord access matches `OrderVoter::VIEW` (which already permits them).
- [ ] Anonymous customer can download VOP from the signed `/objednavka/{id}/dokumenty/vop.pdf` URL produced by `OrderStatusUrlGenerator::generateVopDownload`; tampering with the query string yields 403.
- [ ] `docker compose exec web composer quality` is green.
- [ ] `docker compose exec web composer test` is green (the full suite — the existing `SendOrderConfirmationEmailHandlerTest` and `OrderConfirmationEmailHandler` integration tests must keep passing with the new stub wiring).
- [ ] Manual: regenerate the VOP for an order in two different places — both the pricelist URL and the operating-rules URL on the Definice page differ accordingly. Open one VOP for a place WITHOUT operating rules and confirm the operating-rules URL falls back to the place detail page.
- [ ] `.claude/CUSTOMER_DOCUMENTS.md` row 5 reflects the new generator + storage + access pattern.

## Out of scope

1. **`pouceni-spotrebitele.pdf` and `podminky-opakovanych-plateb.pdf`** stay static. The operator chose VOP-only for now; the same pattern can be extended in a follow-up spec when needed (the `Vop\` namespace generalises naturally to a `LegalDocs\` or per-doc service).
2. **The on-page consent modals** (`templates/public/_terms_and_conditions_content.html.twig`, `templates/public/_recurring_payments_terms_content.html.twig`, the `_term_modal.html.twig` embed) keep their existing Twig content. The customer reads VOP in the modal during order acceptance, then receives the DOCX-rendered version after submission. This means the modal text and the DOCX text **may drift**: the operator must edit both. Out of scope to unify (would require rendering DOCX → HTML inline, which is a much larger change). Document this caveat in the operator-facing CLAUDE.md note in §14.
3. **Backfill of historical orders**: existing orders that already received a `vop.pdf` attachment do **not** get re-emailed. The portal/permalink download for those orders late-generates the new dynamic VOP on first click (see "ensureVopExists" in §7) — the operator should be aware that older orders' historic VOP attachment in their email inbox is the version they actually received; what they download today is generated against the current template.
4. **Caching the rendered PDF on disk.** Each download triggers LibreOffice + Ghostscript + FPDI (~3-5 s end-to-end). For low download volume this is fine. If it becomes a hotspot, cache the stamped PDF beside the DOCX (`var/vop/vop_{orderId}.pdf`) and invalidate on template change. Not now.
5. **Signature on the form annex pages.** Pages 10–11 are the customer's blank withdrawal and complaint forms — they are *theirs* to fill in if they exercise withdrawal or file a complaint. Stamping our customer's signature on them would make no sense. Hardcoded `skipLastPages = 2` reflects this; the constant is wired through service config so a future template with more annexes can change it without touching code beyond `services.php`.
6. **PDF signing in the cryptographic sense** (PAdES, qualified electronic signature). The image stamp is evidentiary, not cryptographic. The customer's actual *legal* assent comes from the existing accept-VOP checkbox + signed contract flow; this spec strengthens the "made part of the contract" leg of OZ § 1751 by visibly tying the standard terms to the customer's signature. A future move to PAdES is a separate, much larger change.
7. **Editing the static DOCX outside Google Docs.** The operator is the source of truth for `templates/documents/vop_template.docx`; this spec doesn't define an admin UI for re-uploading it. (Could be added later if editorial cycles demand it.)

## Open questions

None — proceed.
