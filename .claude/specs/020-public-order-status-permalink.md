# 020 — Public order status permalink (signed URL, lifecycle-aware page)

**Status:** ready
**Type:** feature (new public route + URL signing + email-CTA refactor + replaces /dokonceno)
**Scope:** medium-large (~18 files: 1 new generator, 4 controllers updated/replaced, 1 new template, 1 partial, ~9 email handlers + templates, ~3 test files)
**Depends on:** none — supersedes the just-merged `/dokonceno` work (commits `9172231`, `fa33a21`)

## Problem

Today the customer's only post-payment landing page is `/objednavka/{id}/dokonceno` (`OrderCompleteController`). Two things are wrong with it as a long-term permalink:

1. **It only models the "happy path"**. The page hard-renders "Objednávka dokončena!" and refuses to load for anything other than `OrderStatus::COMPLETED`. A customer who comes back to it a month later, after their contract was terminated for non-payment or expired naturally, sees an error flash and a redirect — not a useful status view. There's no single URL that reflects the current state of the order and contract over its full lifetime.
2. **It's reachable by anyone with the order UUID**. UUIDs are v7 (timestamp-prefixed) and therefore enumerable in a narrow window — an attacker who knows roughly when an order was placed can brute-force the random tail. Whatever sits behind that URL (status, customer name, downloadable contract, invoice, pobočka address) leaks.

We also want this page to be the **CTA destination from every customer-facing email** (order confirmation, contract ready, invoice, payment failed, advance notice, contract expiring, contract terminated, payment default, etc.) so the customer always has one stable, hardened link to come back to. Today's emails point at a mix of `portal_user_order_detail` (auth-only — guest checkouts can't open it) and `portal_dashboard`.

## Goal

A single signed permalink — `/objednavka/{id}/stav?_hash=…` — that:

- Renders the **live state** of the order/contract with a clear Czech badge: *Aktivní · Čeká na platbu · Zaplaceno · Dokončeno · Zrušeno · Expirováno · Smlouva ukončena · Pozastaveno z důvodu nezaplacení · Aktivní (výpověď podaná)*.
- Drives the right next-step CTA per state: pay now (unpaid), cancel recurring (active recurring), retry guidance (failed billing), download docs (completed), contact us (terminated/cancelled).
- Lists every relevant document (smlouva, faktura(s) — recurring contracts accumulate one per month, mapa s vyznačenou jednotkou, provozní řád, VOP, poučení spotřebitele, podmínky opakovaných plateb if applicable, formuláře).
- Shows pobočka contact and a compact lifecycle timeline derived from `AuditLog` rows for the order/contract.
- Replaces `/dokonceno` entirely. The `PaymentReturnController` redirects to a freshly-signed `/stav` URL on successful payment; every email CTA points at the same signed URL.
- Cannot be reached without a valid HMAC signature: a bare `/objednavka/{uuid}/stav` (no `?_hash=`) returns `403`.

## Context (current state)

### Things this replaces / extends

- `src/Controller/Public/OrderCompleteController.php` (route `public_order_complete`) — **deleted** by this spec.
- `templates/public/order_complete.html.twig` — **renamed/rewritten** to `templates/public/order_status.html.twig`, generalised to handle every status.
- `src/Controller/Public/OrderContractDownloadController.php` — **gated** by signature in addition to the existing `OrderStatus::COMPLETED` check.
- `src/Controller/Public/OrderInvoiceDownloadController.php` — same.
- `src/Controller/Public/OrderMapDownloadController.php` — same.
- `src/Controller/Public/PaymentReturnController.php:62-69` — redirects to the new `public_order_status` route with a signed URL minted server-side, instead of `public_order_complete`.

### Existing primitives we reuse

- **`Symfony\Component\HttpFoundation\UriSigner`** — wired throughout the project as a service. Used by `src/Service/RecurringPaymentCancelUrlGenerator.php` (full file is the canonical pattern: `$uriSigner->sign($url)` to mint, `$uriSigner->checkRequest($request)` to verify) and by `src/Controller/Public/CancelRecurringPaymentController.php:34` for verification. Backed by `%kernel.secret%` (= `APP_SECRET` in `.env`).
- **`PriceCalculator::needsRecurringBilling(startDate, endDate)`** (`src/Service/PriceCalculator.php:173`) — already used by `OrderCompleteController` to gate the "Podmínky opakovaných plateb" link.
- **`StorageMapImageGenerator::generate(Storage)`** (`src/Service/StorageMapImageGenerator.php:29`) — already used by both `SendContractReadyEmailHandler` and the just-merged `OrderMapDownloadController`. Returns highlighted PNG bytes.
- **`InvoiceRepository::findAllByOrder(Order)`** (`src/Repository/InvoiceRepository.php:52`) — returns all invoices for an order, ordered by `issuedAt DESC`. Already exists; recurring contracts produce one row per month.
- **`AuditLog` entity** (`src/Entity/AuditLog.php`) — has `(entityType, entityId)` index. `AuditLogger` writes rows on every order/contract/payment event (`logOrderCreated`, `logOrderPaid`, `logOrderCompleted`, `logOrderCancelled`, `logOrderExpired`, `logContractCreated`, `logContractSigned`, `logContractTerminated`, `logStorageReleased`, `logStorageOccupied`). This is the timeline source.
- **`ContractService::calculateOutstandingDebt(Contract, $now)`** (`src/Service/ContractService.php:90`) — returns prorated debt for terminated-with-debt cases. Needed for the "Pozastaveno" state banner.
- **`RecurringPaymentCancelUrlGenerator::generate(Contract)`** — for the active-recurring "Zrušit opakovanou platbu" CTA. Already issues a signed URL.
- **OrderStatus enum** (`src/Enum/OrderStatus.php`): `CREATED · RESERVED · AWAITING_PAYMENT · PAID · COMPLETED · CANCELLED · EXPIRED`. `isTerminal()` covers `COMPLETED|CANCELLED|EXPIRED`.
- **TerminationReason enum** (`src/Enum/TerminationReason.php`): used on `Contract::$terminationReason`. Includes `EXPIRED`, `PAYMENT_FAILURE` (and customer/admin-initiated reasons).
- **`Contract`** has all the recurring-state plumbing already: `nextBillingDate`, `lastBilledAt`, `paidThroughDate`, `failedBillingAttempts`, `goPayParentPaymentId`, `terminationNoticedAt`, `terminatesAt`, `hasActiveRecurringPayment()`, `hasPendingTermination()`, `isTerminated()`, `getEffectiveEndDate()`.

### Email touchpoints to update

Every customer-facing email that today links to `portal_user_order_detail` (auth-only) or `portal_dashboard`. All of these have access to the relevant `Order` (directly or via `Contract::$order`):

| Handler | Trigger | Today's CTA | New CTA |
|---|---|---|---|
| `SendOrderConfirmationEmailHandler` | `OrderPlaced` (signed pre-payment) | `manageUrl` → `portal_user_order_detail` | signed `/stav` |
| `SendContractReadyEmailHandler` | `OrderCompleted` | `portalUrl` → `portal_dashboard` | signed `/stav` |
| `SendInvoiceEmailHandler` | `InvoiceCreated` | (no link today, only attachment) | add signed `/stav` button |
| `SendOrderCancelledEmailHandler` | `OrderCancelled` | (no link today) | add signed `/stav` button |
| `SendRecurringPaymentEstablishedEmailHandler` | `RecurringPaymentEstablished` | `manageUrl` → `portal_user_order_detail` | signed `/stav` |
| `SendRecurringPaymentFailedEmailHandler` | `RecurringPaymentFailed` | (cancelUrl + portal/contact) | add signed `/stav` button |
| `SendRecurringPaymentAdvanceNoticeEmailHandler` | (cron, ≥6-month gap) | per template | add signed `/stav` button |
| `SendContractExpiringReminderHandler` | (cron, before endDate) | `portalUrl` → portal | replace with signed `/stav` |
| `SendContractTerminatedEmailHandler` | `ContractTerminated` | (no link today) | add signed `/stav` button |
| `SendPaymentDefaultEmailHandler` | `ContractTerminatedDueToPaymentFailure` (customer email) | (no link today) | add signed `/stav` button |

The admin variants (`*AdminEmailHandler`) are **out of scope** — admins use the `/portal/admin/orders/{id}` view, not the customer permalink.

### Security model — token strategy decision

Three options were evaluated:

| Option | Mechanism | Pros | Cons |
|---|---|---|---|
| **A. UriSigner (HMAC-signed URL)** ✅ | `?_hash=…` over the full URL, signed with `%kernel.secret%` | Stateless · zero migration · existing pattern in this codebase (`RecurringPaymentCancelUrlGenerator`) · stable per-order URL · negligible code | Cannot revoke a single leaked URL — only global `APP_SECRET` rotation, which invalidates **every** customer's link |
| B. Random `publicAccessToken` column on Order | 32-byte hex column, lookup-by-token | Per-order rotation possible | DB migration · token query path · still need to mint full URL; doesn't actually beat A on threat model |
| C. Opaque slug (Hashids over ID + secret) | Bijective short id | Pretty URL | Same revocation profile as A · adds dependency · ergonomically inferior |

**Decision: A (UriSigner).** Rationale confirmed with product owner: a leaked URL is the customer's responsibility (same as a forwarded password-reset email); whole-secret rotation is not desired. Signing solves the only concrete threat (UUID-v7 enumerability), is stateless, and reuses an established pattern.

`UriSigner::sign()` produces e.g. `https://fajnesklady.cz/objednavka/01935.../stav?_hash=8f3a…`. `checkRequest()` validates `_hash` against the rest of the URL. **Important quirk:** the signature covers the full URL **including** any extra query parameters — adding params later (e.g. `?utm_source=…`) will break signature verification unless they're added before signing. The generator is the only place that signs URLs; consumers must not append params.

### Frontend / template inventory

The just-merged `templates/public/order_complete.html.twig` already has the documents panel + map embed + macro structure we want. This spec **rewrites** that template into `templates/public/order_status.html.twig` with status-aware rendering: the documents panel is reused as a partial, and a status-banner block + state-specific CTAs are added on top.

### What stays untouched

- The portal-side authenticated views (`portal_user_order_detail`, `portal_user_contract_*`, `portal_user_invoice_pdf`) keep their existing `#[IsGranted('ROLE_USER')]` gates and remain accessible for logged-in customers via the portal navigation. The signed `/stav` is the **public** companion for emails / guest checkouts.
- The cancel-recurring page (`/opakovana-platba/{id}/zrusit`) — already signed via the same pattern; we link to it from the status page.
- The signing flow (`/podpis/{token}`) and its token mechanism (`Order::$signingToken`, one-time use) — orthogonal concept, unchanged.

## Architecture

```
Customer email                    PaymentReturnController
   │  (signed /stav URL)             │  (mints fresh signed URL after payment)
   └──────────────┬──────────────────┘
                  ▼
       OrderStatusController       ◀──── verifies UriSigner signature
                  │                         (403 if absent/invalid)
                  ▼
       Compute "display status"     ◀──── pure function over (Order, Contract)
                  │                         → enum case → label + variant
                  ▼
       templates/public/order_status.html.twig
                  │
       ┌──────────┼──────────┬────────────────┬──────────────┐
       ▼          ▼          ▼                ▼              ▼
   Status     Order       State CTA      Documents      Pobočka contact
   banner     summary     (per state)    partial        + Timeline
                                          │
                          ┌────────────── │ ────────────────┐
                          ▼               ▼                 ▼
              public_order_contract  public_order_invoice  public_order_map
              _download              _download             _download
              ALL three controllers re-validate UriSigner on the request
              (each link is signed by the generator at render time)
```

Status mapping (single source of truth, in a service — see Requirement 3):

| Order state | Contract state | Display badge | Variant | Primary CTA |
|---|---|---|---|---|
| `CREATED` / `RESERVED` / `AWAITING_PAYMENT` | n/a (not yet completed) | **Čeká na platbu** | `warning` | "Pokračovat v platbě" → `public_order_payment` |
| `PAID` | n/a (contract not yet attached, transient) | **Zpracovává se** | `info` | refresh hint |
| `COMPLETED` | not terminated, no pending termination, `failedBillingAttempts == 0` | **Aktivní** | `success` | (no primary CTA; show recurring panel if applicable) |
| `COMPLETED` | not terminated, `failedBillingAttempts > 0` | **Aktivní – platba selhala** | `warning` | "Kontaktujte nás" + retry guidance text (no auto-retry button — see Out of scope) |
| `COMPLETED` | `hasPendingTermination()` (notice given, awaiting `terminatesAt`) | **Aktivní (výpověď podána)** | `info` | show termination date |
| `COMPLETED` | terminated, `terminationReason == EXPIRED` (or non-payment, non-failure normal end) | **Dokončeno** | `neutral` | (no CTA) |
| `COMPLETED` | terminated, `terminationReason == PAYMENT_FAILURE` | **Pozastaveno z důvodu nezaplacení** | `error` | "Kontaktujte nás" — show outstanding debt amount |
| `COMPLETED` | terminated, any other reason | **Smlouva ukončena** | `neutral` | (no CTA) |
| `CANCELLED` | n/a | **Zrušeno** | `neutral` | "Vytvořit novou objednávku" → home |
| `EXPIRED` | n/a | **Expirováno** | `neutral` | "Vytvořit novou objednávku" → home |

## Requirements

### 1. New service: `OrderStatusUrlGenerator`

`src/Service/OrderStatusUrlGenerator.php` — thin wrapper around `UriSigner` mirroring `RecurringPaymentCancelUrlGenerator`.

```php
final readonly class OrderStatusUrlGenerator
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private UriSigner $uriSigner,
    ) {
    }

    public function generate(Order $order): string
    {
        $url = $this->urlGenerator->generate(
            'public_order_status',
            ['id' => $order->id->toRfc4122()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        return $this->uriSigner->sign($url);
    }

    public function generateContractDownload(Order $order): string { /* same shape, route public_order_contract_download */ }
    public function generateInvoiceDownload(Order $order, Invoice $invoice): string { /* … invoice id is in path */ }
    public function generateMapDownload(Order $order, bool $forDownload = false): string { /* …append download=1 BEFORE signing if true */ }
}
```

All four methods sign the **complete** URL after `UrlGenerator` produces it. The `?download=1` flag for the map endpoint must be added to the URL before signing — query-string ordering matters for `UriSigner`. Use `RouterInterface` query-merge style or sign manually.

The generator is the **only** code path that produces these URLs going forward — Twig should never call `path()` for the four hardened routes directly. To enforce this, expose the generator as a Twig function `signed_order_url(...)` (small Twig extension). Or simply pass pre-built URLs from the controller into the template. Recommend the latter — a single `OrderStatusViewModel` (see Requirement 4) carries the URLs.

### 2. New route + controller: `OrderStatusController`

`src/Controller/Public/OrderStatusController.php`:

```php
#[Route('/objednavka/{id}/stav', name: 'public_order_status', requirements: ['id' => '[0-9a-f-]{36}'])]
final class OrderStatusController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly ContractRepository $contractRepository,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly UriSigner $uriSigner,
        private readonly OrderStatusViewModelFactory $viewModelFactory,
    ) {}

    public function __invoke(Request $request, string $id): Response
    {
        if (!$this->uriSigner->checkRequest($request)) {
            throw $this->createAccessDeniedException('Neplatný nebo expirovaný odkaz.');
        }

        if (!Uuid::isValid($id)) {
            throw new NotFoundHttpException('Objednávka nenalezena.');
        }

        $order = $this->orderRepository->find(Uuid::fromString($id));
        if (null === $order) {
            throw new NotFoundHttpException('Objednávka nenalezena.');
        }

        $viewModel = $this->viewModelFactory->build($order);

        return $this->render('public/order_status.html.twig', ['vm' => $viewModel]);
    }
}
```

The `createAccessDeniedException` mirrors `CancelRecurringPaymentController:34` exactly. Symfony will render a 403 page; consider a small custom error template later (out of scope).

### 3. Status mapping service

`src/Service/Order/OrderDisplayStatusResolver.php` (final readonly) — single function `resolve(Order, ?Contract): OrderDisplayStatus`. The result is a value object:

```php
final readonly class OrderDisplayStatus
{
    public function __construct(
        public OrderDisplayStatusCase $case,   // enum: ACTIVE, ACTIVE_BILLING_FAILED, ACTIVE_TERMINATION_PENDING,
                                              // AWAITING_PAYMENT, PROCESSING, COMPLETED_ENDED,
                                              // SUSPENDED_PAYMENT_FAILURE, TERMINATED, CANCELLED, EXPIRED
        public string $label,                  // Czech label
        public string $variant,                // 'success' | 'warning' | 'error' | 'info' | 'neutral'
        public ?string $description = null,    // optional sub-message
    ) {}
}
```

Mapping logic mirrors the table in *Architecture*. Unit-tested as a pure function with `OrderFixtures` / `ContractFixtures` (see Requirement 9). Putting this in a separate service keeps the controller thin and the template logic-free.

### 4. View model factory

`src/Service/Order/OrderStatusViewModelFactory.php` — assembles everything the template needs, including pre-signed URLs:

```php
final readonly class OrderStatusViewModelFactory
{
    public function __construct(
        private ContractRepository $contractRepository,
        private InvoiceRepository $invoiceRepository,
        private OrderDisplayStatusResolver $statusResolver,
        private OrderStatusUrlGenerator $urlGenerator,
        private RecurringPaymentCancelUrlGenerator $cancelUrlGenerator,
        private PriceCalculator $priceCalculator,
        private ContractService $contractService,
        private AuditLogRepository $auditLogRepository,    // NEW — see Req 5
        private UrlGeneratorInterface $symfonyUrlGenerator, // for unsigned routes (e.g. payment, home)
        private ClockInterface $clock,
    ) {}

    public function build(Order $order): OrderStatusViewModel { /* … */ }
}

final readonly class OrderStatusViewModel
{
    public function __construct(
        public Order $order,
        public ?Contract $contract,
        public Storage $storage,
        public StorageType $storageType,
        public Place $place,
        public OrderDisplayStatus $status,
        /** @var Invoice[] */
        public array $invoices,
        public bool $isRecurring,
        public ?int $outstandingDebtCzk,           // null if no debt
        /** @var array<int, array{occurredAt: \DateTimeImmutable, label: string, icon: string}> */
        public array $timeline,
        // pre-signed URLs (null when not applicable)
        public ?string $payNowUrl,                 // unsigned — public_order_payment is already pseudo-public
        public ?string $cancelRecurringUrl,        // signed via existing RecurringPaymentCancelUrlGenerator
        public ?string $contractDownloadUrl,       // signed
        public ?string $mapEmbedUrl,               // signed (?download not set)
        public ?string $mapDownloadUrl,            // signed (?download=1)
        /** @var array<int, array{name: string, url: string}> */
        public array $invoiceDownloadUrls,         // each signed
    ) {}
}
```

Builds in one place keeps the template dumb. Empty-states (no contract, no invoices, no map) are represented by `null`/`[]` and the template branches on those.

### 5. New repository method for the timeline

`src/Repository/AuditLogRepository.php` — does not exist yet; create it (mirroring `InvoiceRepository`). One method needed:

```php
/**
 * @return AuditLog[]
 */
public function findForOrderTimeline(Order $order, ?Contract $contract): array
{
    $qb = $this->entityManager->createQueryBuilder()
        ->select('a')
        ->from(AuditLog::class, 'a')
        ->where('(a.entityType = :orderType AND a.entityId = :orderId)')
        ->setParameter('orderType', 'Order')
        ->setParameter('orderId', $order->id->toRfc4122())
        ->orderBy('a.createdAt', 'ASC');

    if (null !== $contract) {
        $qb->orWhere('(a.entityType = :contractType AND a.entityId = :contractId)')
            ->setParameter('contractType', 'Contract')
            ->setParameter('contractId', $contract->id->toRfc4122());
    }

    return $qb->getQuery()->getResult();
}
```

The view-model factory translates the audit rows into `{occurredAt, label, icon}` triplets via a small switch on `eventType` ("OrderCreated" → "Objednávka vytvořena" + 📝 etc.). Render compactly — the timeline is a sidebar element, not the main content.

If an event type appears that the switch doesn't recognise, **silently skip it** (do not show "OrderXyz" raw to customers). Log nothing — just filter. New event types will be added with explicit Czech labels by future devs.

### 6. Template: `templates/public/order_status.html.twig`

Built from the just-merged `order_complete.html.twig` skeleton. Top-to-bottom structure:

1. **Status banner** — full-width card; icon + Czech label + sub-description from `vm.status.description`. Variant-driven Tailwind classes (`bg-green-50/border-green-200/text-green-800` for `success`, etc.).
2. **State-specific CTA row** — only rendered when `vm.payNowUrl` or `vm.cancelRecurringUrl` is non-null. Big primary button. For terminated/cancelled states: an outline "Vytvořit novou objednávku" linking to `app_home`.
3. **Order summary card** — same shape as today's "Detail objednávky" panel. Always rendered.
4. **Recurring info card** — only when `vm.isRecurring && vm.contract && vm.contract.hasActiveRecurringPayment()`: monthly amount, `nextBillingDate`, `lastBilledAt`, `paidThroughDate`, "Zrušit opakovanou platbu" linking to `vm.cancelRecurringUrl`.
5. **Failed-billing notice** — only for `OrderDisplayStatusCase::ACTIVE_BILLING_FAILED`: "Poslední automatický pokus o platbu selhal. Zkontrolujte prosím vaši kartu nebo nás kontaktujte na simek@fajnesklady.cz." No retry button (out of scope).
6. **Outstanding-debt notice** — only when `vm.outstandingDebtCzk !== null`: "Z důvodu neuhrazené platby evidujeme dluh ve výši X Kč. Kontaktujte nás prosím na simek@fajnesklady.cz."
7. **Map** — only when `vm.mapEmbedUrl !== null` (place has `mapImagePath` AND order is `COMPLETED`).
8. **Documents partial** — extracted from today's inline block into `templates/components/order_status_documents.html.twig`. Receives `{vm}`. Renders the three groups (smluvní, pobočka, právní). Rows are gated by viewmodel nullability — no twig-level business logic. The contract row uses `vm.contractDownloadUrl`; invoice rows iterate `vm.invoiceDownloadUrls`; map rows use `vm.mapDownloadUrl`.
9. **Pobočka contact** — name, address, opening hours (if `place.description` carries them — currently it does), phone (hardcoded `+420 605 522 566` like today's template).
10. **Timeline** — sidebar/below; renders `vm.timeline` as a vertical stepper. Compact.
11. **Email reminder** — small note "Tento odkaz najdete v e-mailech od nás" (functional reassurance).

The `extends app.user ? 'portal/layout.html.twig' : 'user/layout.html.twig'` shim from today's template stays.

### 7. Update download endpoints to enforce signature

`src/Controller/Public/OrderContractDownloadController.php`, `OrderInvoiceDownloadController.php`, `OrderMapDownloadController.php`:

- Inject `UriSigner $uriSigner`.
- Inject `Request $request` parameter on `__invoke`.
- First line of method: `if (!$this->uriSigner->checkRequest($request)) { throw $this->createAccessDeniedException(...); }`.
- Keep all existing logic (UUID validity, status === COMPLETED gate, file path resolution).

Result: a bare `/objednavka/.../dokumenty/smlouva.pdf` returns 403; only links rendered by `OrderStatusUrlGenerator` (signed) work.

### 8. Replace `/dokonceno` with `/stav`

- Delete `src/Controller/Public/OrderCompleteController.php` and `templates/public/order_complete.html.twig`.
- Update `src/Controller/Public/PaymentReturnController.php:62-69`: replace `redirectToRoute('public_order_complete', ...)` with `return new RedirectResponse($this->orderStatusUrlGenerator->generate($order));`. Inject the new service. Both branches that redirect to the success page (after `COMPLETED` and after `PAID`) use this.
- Search the codebase for any other references to `public_order_complete`:

```bash
grep -rn "public_order_complete" src/ templates/ tests/
```

There should be none after this change. CI fails fast if any straggler is missed.

### 9. Email handler updates

For each handler in the table in *Context*, inject `OrderStatusUrlGenerator`, mint the URL once, pass it to the template under context key `statusUrl`. Then update the corresponding Twig template under `templates/email/` to add or replace the CTA button.

Pattern (e.g. for `SendContractReadyEmailHandler.php`):

```php
public function __construct(
    // … existing …
    private OrderStatusUrlGenerator $statusUrlGenerator,
) {}

// in __invoke:
$statusUrl = $this->statusUrlGenerator->generate($contract->order);

$email->context([
    // … existing context …
    'statusUrl' => $statusUrl,
]);
```

Template change pattern — replace the existing primary CTA with:

```twig
<div style="text-align: center;">
    <a href="{{ statusUrl }}" class="button">Zobrazit stav objednávky</a>
</div>
```

Specific per-handler notes:

- `SendOrderConfirmationEmailHandler` (pre-payment): `manageUrl` in context becomes `statusUrl`. Template's "Spravovat objednávku" button now lands on the signed status page (which will show "Čeká na platbu" + "Pokračovat v platbě" CTA).
- `SendContractReadyEmailHandler` (post-payment): existing button text "Zobrazit smlouvu" linking to `portalUrl` is replaced by "Zobrazit stav objednávky" linking to `statusUrl`. Drop the `portalUrl` context key from this handler — the status page is the new destination. (Logged-in users still have portal nav.)
- `SendInvoiceEmailHandler`: add a new "Zobrazit stav objednávky" button below the existing invoice content.
- `SendOrderCancelledEmailHandler`: the status page will show "Zrušeno" — add the button.
- `SendRecurringPaymentEstablishedEmailHandler`: replace `manageUrl` with `statusUrl`.
- `SendRecurringPaymentFailedEmailHandler`: add `statusUrl` button alongside the existing cancellation/contact info.
- `SendRecurringPaymentAdvanceNoticeEmailHandler`: add `statusUrl` button.
- `SendContractExpiringReminderHandler`: replace `portalUrl` with `statusUrl`.
- `SendContractTerminatedEmailHandler`: add `statusUrl` button.
- `SendPaymentDefaultEmailHandler` (customer email only — admin variant unchanged): add `statusUrl` button.

### 10. Tests

- **`tests/Unit/Service/Order/OrderDisplayStatusResolverTest.php`** — table-driven, every row from the status mapping table. Use `MockClock` (`2025-06-15 12:00:00 UTC`) and the existing fixtures (`OrderFixtures`, `ContractFixtures`) where they fit; build minimal entities directly otherwise.
- **`tests/Integration/Controller/Public/OrderStatusControllerTest.php`** —
  - Bare `/objednavka/{uuid}/stav` (no `_hash`) → 403.
  - Tampered hash → 403.
  - Invalid UUID format → 404 (post-signature check, but the route requirement `[0-9a-f-]{36}` should already 404 it — verify).
  - Valid signed URL for fixture order in `COMPLETED` state → 200, body contains "Aktivní" badge, contract download link is also signed (`?_hash=` present in href).
  - Valid signed URL for fixture order in `CANCELLED` state → 200, body contains "Zrušeno", no payment CTA.
  - Valid signed URL for fixture order in `AWAITING_PAYMENT` state → 200, body contains "Čeká na platbu", "Pokračovat v platbě" link present.
  - Valid signed URL but order not found → 404.
- **`tests/Integration/Controller/Public/OrderContractDownloadControllerTest.php`** (or extend if exists) — bare URL → 403, signed URL → 200. Same pattern for invoice and map controllers.
- **`tests/Integration/Controller/Public/PaymentReturnControllerTest.php`** — assert the redirect Location header matches `~^/objednavka/[0-9a-f-]+/stav\?_hash=[a-zA-Z0-9_-]+$~` after a successful payment (not the old `/dokonceno`).
- **Email handler tests** — for each handler, assert the rendered email body contains a substring matching `~/objednavka/[0-9a-f-]+/stav\?_hash=~`. Reuse whatever existing email-handler test pattern is in the repo (search `tests/` for `assertEmail` usage).
- **No new test for the view model factory** — covered transitively by the controller integration test. Add one if the factory grows non-trivial branching during implementation.

### 11. Documentation

- Update `.claude/specs/PROJECT_MAP.md`:
  - Routes section: replace `/objednavka/{id}/dokonceno → OrderCompleteController` with `/objednavka/{id}/stav → OrderStatusController (UriSigner-protected)`.
  - Services section: add `OrderStatusUrlGenerator`, `OrderDisplayStatusResolver`, `OrderStatusViewModelFactory`.
- Update `.claude/CUSTOMER_DOCUMENTS.md` (if/when Spec 010 is implemented and creates that file) — point at `/stav` instead of `/dokonceno`. If the file doesn't exist yet, **skip** — Spec 010 will pick up the new route name.
- No changes to `CLAUDE.md` or `.claude/COMPLIANCE.md` — none of the rules there bind on the new permalink mechanics.

## Acceptance

- `docker compose exec web composer quality` is green.
- Hitting `https://…/objednavka/{any-valid-uuid}/stav` (no `_hash`) returns 403 with the Czech "Neplatný nebo expirovaný odkaz" message.
- Hitting the URL returned by `OrderStatusUrlGenerator::generate()` for a fixture order returns 200 and renders the correct status badge per fixture state. Verified for at least: `AWAITING_PAYMENT`, `COMPLETED + active recurring`, `COMPLETED + terminated for PAYMENT_FAILURE`, `CANCELLED`, `EXPIRED`.
- Document download URLs (`/objednavka/{id}/dokumenty/smlouva.pdf` etc.) without `_hash` return 403; with the signed URL minted by the generator they return 200 + the file (existing status-COMPLETED gate still applies on top).
- After paying for an order in dev fixtures, `PaymentReturnController` redirects to `/objednavka/{id}/stav?_hash=…` (not `/dokonceno`).
- Every email touchpoint in Requirement 9 contains a button linking to a signed `/stav` URL. Verified by capturing the rendered emails in the test suite.
- The signed URL captured from an email is stable across page reloads — same hash returned by the generator on every call (because `UriSigner::sign` is deterministic given identical inputs).
- `grep -rn "public_order_complete"` returns nothing in `src/`, `templates/`, or `tests/`.
- The status page renders correctly for **anonymous** visitors as well as logged-in `ROLE_USER` (basic smoke check: the layout adapts via the existing `app.user ? portal_layout : user_layout` extends pattern; nothing else differs).

## Out of scope

- **One-click "retry payment" CTA for failed recurring billing**. Requires a fresh GoPay one-shot payment for the prorated outstanding amount, plus parent-token diagnosis (overlaps with spec 018's customer-cancellation detection). Bundling it here would balloon the scope. The page communicates the failed state and a contact link; explicit follow-up spec when the rest of 018 is settled.
- **Per-order URL revocation**. UriSigner globally signs by `APP_SECRET` only; rotating the secret invalidates every customer link. If, in the future, we have a confirmed-leak incident, a follow-up can introduce a `publicAccessTokenRevokedAt` column gated in the controller alongside the signature check. Not needed today.
- **Custom 403 error page** for invalid signature. Symfony's default 403 with the Czech flash is sufficient; theming is a separate UX polish.
- **Backwards compatibility with already-issued `/dokonceno` URLs**. Per product confirmation we are still in development; in-flight emails point at portal pages, not at `/dokonceno`. The route is deleted outright.
- **Translating the page or emails to other languages**. Site is Czech-only. When/if internationalisation lands, the status mapping switch and email templates will need a translation pass — separate effort.
- **A Stimulus-driven auto-refresh** on the status page (so customers see "Čeká na platbu" → "Aktivní" without reload). Plain server render is fine v1; the page is a permalink, not a real-time dashboard.
- **Admin/landlord-side variants** of the page. They have their own admin views (`/portal/admin/orders/{id}` and `/portal/landlord/orders/{id}`); the public permalink is purely customer-facing.
- **Counting unique visitors / analytics** on the page. No instrumentation in v1; if needed later, attach Plausible / similar via the standard layout.
- **Persisting the generated signed URL** on the Order to keep it stable across `APP_SECRET` rotations. `UriSigner::sign` is deterministic for a given `(URL, secret)` so URLs remain the same as long as the secret doesn't change — that's the explicit trade-off chosen.
- **Linking the public status page from the authenticated portal order detail.** Logged-in customers already have the rich `/portal/objednavky/{id}` view; sending them through the public page would be a sideways step.
- **Caching the rendered HTML.** Each request runs a status calculation + a few queries — well under any meaningful budget. The map endpoint already has `Cache-Control: private, max-age=300`.

## Open questions

None — proceed.
