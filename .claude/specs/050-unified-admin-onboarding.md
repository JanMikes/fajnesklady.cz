# 050 — Unified admin onboarding: single dynamic Live Component form, storage map, always-sign VOP, fix payment flow

**Status:** done
**Type:** feature (rewrite) + bug-fix bundle
**Scope:** large (~35 files: 1 Live Component + template, 1 FormData + FormType, 1 Command + Handler, 1 Stimulus bridge, 1 migration, updates to signing controller/handler/template + order status template + OrderService, delete 10 old files, rewrite tests)
**Depends on:** spec 008 (OrderForm Live Component pattern), spec 043 (CustomerBillingSituation), spec 047 (PlaceOccupancyMap Live Component + map pattern), spec 049 (BANK_TRANSFER + VariableSymbolGenerator)

## Problem

The admin onboarding has two separate forms ("Digitální onboarding" and "Migrace papírové smlouvy") with ~60% duplicated logic across 10 files. Both suffer from critical bugs:

1. **Occupied storages shown** — the `ChoiceType` dropdown for storage is populated at form-build time with ALL storages, ignoring availability for the selected dates. Admins can (and do) select occupied units.
2. **BANK_TRANSFER signing flow broken** — `CustomerSigningController:128` only redirects to the payment page for `GOPAY`. Bank transfer orders go to the completion page — the customer never sees the QR code or bank details. The order sits in `RESERVED` forever.
3. **Migrate auto-completes without customer VOP acceptance** — `AdminMigrateCustomerHandler` signs, pays, and completes the order in one transaction. The customer never consents to VOP, violating the business requirement that every order requires explicit VOP acceptance.
4. **No payment CTA on status page** — when an onboarding order is signed but awaiting payment, the `/stav` page shows no clear "Zaplatit" button. The customer doesn't know how to proceed.
5. **Static form** — no cascading, no dynamic fields. The admin must manually cross-reference which storages are available for which dates at which place.

## Goal

A single dynamic Live Component form at `/portal/admin/onboarding` that mirrors the public order flow: cascading field selection (rental type → place → storage type → dates → available storage from inline map), admin-only extras (payment method, pricing overrides, optional contract upload, variable symbol), and a consistent post-submission flow where the customer ALWAYS receives a signing link, signs VOP + provides digital signature, and then proceeds to payment (or auto-completes for external/free).

## Context (current state)

### Files to delete (old two-form system)
- `src/Controller/Admin/AdminCreateOnboardingController.php` — digital onboarding page
- `src/Controller/Admin/AdminMigrateCustomerController.php` — migrate page
- `src/Form/AdminCreateOnboardingFormData.php` — digital form data (317 lines)
- `src/Form/AdminCreateOnboardingFormType.php` — digital form type (195 lines)
- `src/Form/AdminMigrateCustomerFormData.php` — migrate form data (285 lines)
- `src/Form/AdminMigrateCustomerFormType.php` — migrate form type (193 lines)
- `src/Command/AdminCreateOnboardingCommand.php` — digital command
- `src/Command/AdminCreateOnboardingHandler.php` — digital handler (135 lines)
- `src/Command/AdminMigrateCustomerCommand.php` — migrate command
- `src/Command/AdminMigrateCustomerHandler.php` — migrate handler (143 lines)
- `templates/admin/onboarding/digital.html.twig`
- `templates/admin/onboarding/migrate.html.twig`

### Files to keep / reuse
- `src/Controller/Admin/AdminOnboardingController.php` — currently renders index with two cards; will render the unified form
- `templates/admin/onboarding/index.html.twig` — will be rewritten to host the Live Component
- `src/Controller/Public/CustomerSigningController.php:128` — needs BANK_TRANSFER fix
- `src/Command/CustomerSignOnboardingHandler.php:66-67` — needs BANK_TRANSFER branch
- `src/Service/OrderService.php:163-206` (`completeOrder()`) — needs uploaded contract propagation
- `templates/public/customer_signing.html.twig` — needs paper-contract-aware rendering
- `templates/public/order_status.html.twig` — needs payment CTA

### Live Component precedents
- `src/Twig/Components/OrderForm.php` — public order form Live Component with `ComponentWithFormTrait`, `selectStorage` LiveAction, `validateField`, `submit`. **Primary pattern to follow.**
- `src/Twig/Components/PlaceOccupancyMap.php` — demonstrates Konva map inside a Live Component with `data-live-ignore` on canvas container + `storagesValueChanged()` for re-renders.
- `assets/controllers/order_map_bridge_controller.js` — bridges `storage-map:select` events to Live Component actions.

### Storage availability
- `src/Service/StorageAvailabilityChecker.php` — `isAvailable(Storage, startDate, endDate?, excludeOrder?, excludeContract?): bool`. Checks storage status, unavailability records, overlapping orders, overlapping contracts.
- `src/Service/StorageAssignment.php` — `findFirstAvailable(StorageType, startDate, endDate?): ?Storage`. Auto-assigns.

### Key entity fields on Order
- `isAdminCreated: ?bool` (line 83), `signingToken: ?string` (line 86)
- `paymentMethod: PaymentMethod`, `variableSymbol: ?string` (line 92)
- `markAsAdminCreated()` (line 397), `setSigningToken()` (line 402), `setOnboardingBillingTerms()` (line 459)
- `assignVariableSymbol()` (line 417)

### Variable symbol
- `src/Service/Payment/VariableSymbolGenerator.php` — generates unique 10-digit CRC32 symbols
- Already wired into `AdminCreateOnboardingHandler:80-85`: uses override if provided, auto-generates otherwise

## Architecture

```
                   AdminOnboardingController
                          │
                          ▼
    templates/admin/onboarding/index.html.twig
    ├─ {{ component('AdminOnboardingForm') }}            ← Live Component
    │    ├─ form (novalidate) + cascading fields
    │    │   ├─ Rental type (radio)
    │    │   ├─ Place (select, all active places)
    │    │   ├─ Storage type (select, dynamic for place)
    │    │   ├─ Dates (start + end for LIMITED)
    │    │   ├─ Customer info (email, name, phone, etc.)
    │    │   ├─ Pricing mode + payment method + billing mode
    │    │   ├─ Optional contract upload + variable symbol
    │    │   └─ Submit
    │    └─ Inline storage map (Konva canvas, data-live-ignore)
    │         on click → storage-map:select → bridge → selectStorage LiveAction
    │
    Submit LiveAction:
        validate → dispatch AdminOnboardingCommand
        → handler creates order + signing token
        → event triggers signing-link email
        → flash success + redirect
```

Post-submission customer flow:
```
Email (signing link)
     │
     ▼
/podpis/{token}
├─ Has uploaded contract → VOP + GDPR + signature canvas (no contract section)
├─ No contract → contract + VOP + GDPR + signature canvas (same as today)
     │
     ▼  (CustomerSignOnboardingHandler)
├─ EXTERNAL/FREE → confirmPayment + CompleteOrder → /podpis/dokonceno/{id}
├─ GOPAY → stays RESERVED → redirect to /objednavka/{id}/platba (GoPay)
├─ BANK_TRANSFER → stays RESERVED → redirect to /objednavka/{id}/platba (QR + bank details)
```

## Requirements

### 1. New `AdminOnboardingFormData` (`src/Form/AdminOnboardingFormData.php`)

Unifies `AdminCreateOnboardingFormData` + `AdminMigrateCustomerFormData`. All fields from both, with the contract upload becoming optional.

```php
final class AdminOnboardingFormData implements HasBillingAddress
{
    // --- Customer ---
    #[Assert\NotBlank]
    #[Assert\Email]
    public string $email = '';

    #[Assert\NotBlank]
    public string $firstName = '';

    #[Assert\NotBlank]
    public string $lastName = '';

    public ?string $phone = null;
    public ?\DateTimeImmutable $birthDate = null;

    // --- Company ---
    public bool $invoiceToCompany = false;
    public ?string $companyName = null;
    public ?string $companyId = null;
    public ?string $companyVatId = null;

    // --- Billing address (HasBillingAddress interface) ---
    public string $billingStreet = '';
    public string $billingCity = '';
    public string $billingPostalCode = '';
    public bool $addressOverride = false;

    // --- Rental ---
    public ?RentalType $rentalType = null;
    public ?ExpectedDuration $expectedDuration = null;
    public ?\DateTimeImmutable $startDate = null;
    public ?\DateTimeImmutable $endDate = null;

    // --- Pricing ---
    public string $monthlyPriceMode = 'standard'; // 'standard' | 'custom' | 'free'
    public ?int $customMonthlyPriceInCzk = null;

    // --- Payment ---
    public PaymentMethod $paymentMethod = PaymentMethod::GOPAY;
    public ?BillingMode $billingMode = null;
    public ?PaymentFrequency $paymentFrequency = null;

    // --- External prepayment ---
    public bool $isExternallyPrepaid = false;
    public ?\DateTimeImmutable $paidThroughDate = null;

    // --- Optional contract document (replaces migrate's required upload) ---
    public ?UploadedFile $contractDocument = null;

    // --- Variable symbol override (BANK_TRANSFER only) ---
    public ?string $variableSymbol = null;
}
```

**Validations** — merge logic from both old FormData classes:
- Same company/birth-date/address/dates validations as `AdminCreateOnboardingFormData`
- Same billing mode constraints (BANK_TRANSFER → MANUAL_RECURRING, UNLIMITED → forces AUTO unless BANK_TRANSFER, etc.)
- Same pricing mode constraints (custom requires amount > 0)
- `contractDocument`: optional, when present validate MIME (pdf, jpeg, png) + max 10 MB
- `variableSymbol`: optional, when present validate numeric-only + length ≤ 10
- Keep the `validateExternalIsPrepaid` callback from spec 043: EXTERNAL + non-free requires `paidThroughDate`

### 2. New `AdminOnboardingFormType` (`src/Form/AdminOnboardingFormType.php`)

Mirrors the old `AdminCreateOnboardingFormType` but adds:
- `contractDocument`: `FileType` (optional, mapped, `required: false`)
- `variableSymbol`: `TextType` (optional, `required: false`, `attr: ['placeholder' => 'Ponechte prázdné pro automatické vygenerování']`)
- `placeId` and `storageTypeId` are NOT form fields — they are LiveProps on the component (see req. 3). The form only contains fields that map to `AdminOnboardingFormData`.

### 3. New `AdminOnboardingForm` Live Component (`src/Twig/Components/AdminOnboardingForm.php`)

```php
#[AsLiveComponent]
final class AdminOnboardingForm extends AbstractController
{
    use ComponentWithFormTrait;
    use DefaultActionTrait;

    // --- Cascading selection LiveProps (NOT form fields) ---
    #[LiveProp(writable: true)]
    public ?string $placeId = null;

    #[LiveProp(writable: true)]
    public ?string $storageTypeId = null;

    #[LiveProp(writable: true)]
    public ?string $storageId = null;
}
```

**Key methods:**

- `instantiateForm()`: Creates `AdminOnboardingFormType` with `AdminOnboardingFormData`
- `getPlaces(): array` — returns all active `Place` entities (for the place dropdown)
- `getStorageTypes(): array` — returns `StorageType[]` for the selected place (empty if no place selected)
- `getSelectedPlace(): ?Place` — resolves `$placeId` to entity
- `getSelectedStorageType(): ?StorageType` — resolves `$storageTypeId` to entity
- `getSelectedStorage(): ?Storage` — resolves `$storageId` to entity
- `getStoragesJson(): string` — computes JSON payload for the Konva map. For each storage of the selected type, checks availability via `StorageAvailabilityChecker::isAvailable()` for the form's selected date range. Returns status `available` or `unavailable`. Returns `'[]'` if place/type/dates are incomplete.
- `getPaymentSchedule(): ?PaymentSchedule` — calls `PriceCalculator::buildPaymentSchedule()` for live price preview when storage + dates are complete.

**LiveActions:**

- `#[LiveAction] selectStorage(string $storageId)`: Sets `$this->storageId`. Mirrors `OrderForm::selectStorage()`.
- `#[LiveAction] onPlaceChange()`: Resets `$this->storageTypeId = null` and `$this->storageId = null` when place changes.
- `#[LiveAction] onStorageTypeChange()`: Resets `$this->storageId = null` when storage type changes.
- `#[LiveAction] validateField(string $field)`: Per-field live validation, mirrors `OrderForm::validateField()`.
- `#[LiveAction] submit()`: Validates the full form. If valid + `storageId` is set, dispatches `AdminOnboardingCommand`. Flashes success with signing URL. Redirects to `admin_order_list`.

**Injections:** `PlaceRepository`, `StorageTypeRepository`, `StorageRepository`, `StorageAvailabilityChecker`, `PriceCalculator`, `MessageBusInterface`, `ClockInterface`, `ProvideIdentity`, `PlaceFileUploader`, `LoggerInterface`.

### 4. Live Component template (`templates/components/AdminOnboardingForm.html.twig`)

Layout: single-column form with sections (mirrors the public OrderForm structure but admin-styled):

1. **Typ pronájmu** — radio: UNLIMITED / LIMITED
2. **Pobočka** — `<select>` with all places, `data-action="change->live#action"` → `onPlaceChange`
3. **Typ skladovací jednotky** — `<select>` with storage types for selected place (hidden until place selected), `data-action="change->live#action"` → `onStorageTypeChange`
4. **Datum** — startDate (required), endDate (only for LIMITED)
5. **Mapa skladu** — inline Konva map (only when place + type + dates are complete). Pattern from `PlaceOccupancyMap.html.twig`:
   ```twig
   {% if this.selectedPlace and this.selectedStorageType and this.selectedPlace.mapImagePath %}
       <div data-controller="storage-map"
            data-storage-map-map-image-value="{{ asset('uploads/' ~ this.selectedPlace.mapImagePath) }}"
            data-storage-map-storages-value="{{ this.storagesJson|e('html_attr') }}"
            data-storage-map-place-id-value="{{ this.placeId }}"
            data-storage-map-current-storage-type-id-value="{{ this.storageTypeId }}"
            data-storage-map-select-mode-value="true"
            data-storage-map-highlight-storage-value="{{ this.storageId ?? '' }}">
           <div data-storage-map-target="container" data-live-ignore
                class="border border-gray-300 rounded-lg w-full" style="min-height: 400px;"></div>
       </div>
   {% endif %}
   ```
6. **Selected storage info** — when `storageId` is set, show storage number + dimensions + pricing
7. **Kontaktní údaje** — email, name, phone, birthDate (same as OrderForm)
8. **Fakturační údaje** — company checkbox + IČO/DIČ/name, billing address
9. **Cenový model** — radio: Standardní / Individuální / Zdarma + customMonthlyPriceInCzk
10. **Platební metoda** — radio: GoPay / Bankovní převod / Externí platba
11. **Variabilní symbol** — text field (only visible when BANK_TRANSFER selected)
12. **Frekvence a způsob plateb** — paymentFrequency + billingMode radios (same show/hide logic as old forms)
13. **Externí předplatné** — isExternallyPrepaid checkbox + paidThroughDate
14. **Existující smlouva** — `contractDocument` file upload (optional)
15. **Submit** — "Vytvořit onboarding"

**Bridge for map clicks**: Wrap the entire component in a `data-controller="admin-onboarding-bridge"` div. The bridge controller catches `storage-map:select` custom events and calls the Live Component's `selectStorage` action. Reuse the pattern from `order_map_bridge_controller.js`.

### 5. New Stimulus bridge (`assets/controllers/admin_onboarding_bridge_controller.js`)

Copy `order_map_bridge_controller.js` with minimal changes:
- No `mapTargetConnected` needed (map is inside the Live Component)
- Listen for `storage-map:select` events bubbling up
- Call `selectStorage` LiveAction via `getComponent()` API

Alternatively, the map is INSIDE the Live Component template, so the bridge approach needs adapting. The bridge controller should be placed on a parent element that wraps both the Live Component root and catches the `storage-map:select` event. Since the map is inside the Live Component (with `data-live-ignore` on canvas), the event bubbles up through the Live Component root. The bridge catches it and calls the Live Component action.

Simplest approach: place `data-controller="admin-onboarding-bridge"` on the wrapping `<div {{ attributes }}>` of the Live Component template itself. The bridge listens on the same element. Use `getComponent(this.element)` to get the Live Component reference.

```js
import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';

export default class extends Controller {
    async handleStorageSelect(event) {
        const storageId = event.detail?.storageId;
        if (!storageId) return;

        const component = await getComponent(this.element);
        await component.action('selectStorage', { storageId });
    }
}
```

Template wiring:
```twig
<div {{ attributes.defaults({
    'data-controller': 'admin-onboarding-bridge',
    'data-action': 'storage-map:select->admin-onboarding-bridge#handleStorageSelect',
}) }}>
```

### 6. New `AdminOnboardingCommand` (`src/Command/AdminOnboardingCommand.php`)

Unified command replacing both `AdminCreateOnboardingCommand` and `AdminMigrateCustomerCommand`:

```php
final readonly class AdminOnboardingCommand
{
    public function __construct(
        public string $email,
        public string $firstName,
        public string $lastName,
        public ?string $phone,
        public ?\DateTimeImmutable $birthDate,
        public ?string $companyName,
        public ?string $companyId,
        public ?string $companyVatId,
        public string $billingStreet,
        public string $billingCity,
        public string $billingPostalCode,
        public Storage $storage,
        public StorageType $storageType,
        public Place $place,
        public RentalType $rentalType,
        public \DateTimeImmutable $startDate,
        public ?\DateTimeImmutable $endDate,
        public PaymentMethod $paymentMethod,
        public ?int $individualMonthlyAmount,    // halere; null = standard; 0 = free
        public ?\DateTimeImmutable $paidThroughDate,
        public Uuid $createdByAdminId,
        public BillingMode $billingMode,
        public ?ExpectedDuration $expectedDuration,
        public PaymentFrequency $paymentFrequency,
        public ?string $variableSymbolOverride,   // null = auto-generate for BANK_TRANSFER
        public ?string $uploadedContractPath,     // null = no paper contract
    ) {}
}
```

### 7. New `AdminOnboardingHandler` (`src/Command/AdminOnboardingHandler.php`)

Unified handler, merging logic from both old handlers. Flow:

1. **Get or create user** — `GetOrCreateUserByEmailCommand` or direct `UserRepository::findByEmail()` + update profile
2. **Update billing info** — `user->updateBillingInfo()`
3. **Create order** — `OrderService::createOrder()` with `preSelectedStorage`
4. **Mark as admin-created** — `order->markAsAdminCreated()`
5. **Set payment method** — EXTERNAL forced for free/externally-prepaid; otherwise use command's method
6. **Assign variable symbol** — if BANK_TRANSFER: use override or auto-generate via `VariableSymbolGenerator`
7. **Set onboarding billing terms** — `order->setOnboardingBillingTerms()`
8. **Store uploaded contract path** — if `uploadedContractPath` is not null: `order->setUploadedContractDocumentPath()`
9. **Create signing token** — `order->setSigningToken(bin2hex(random_bytes(32)))`, extend expiration to 30 days
10. **Record event** — `AdminOnboardingInitiated` (triggers signing-link email)
11. **Return Order**

### 8. New `Order.uploadedContractDocumentPath` column

Add nullable string column to `Order` entity:

```php
#[ORM\Column(length: 500, nullable: true)]
public private(set) ?string $uploadedContractDocumentPath = null;

public function setUploadedContractDocumentPath(string $path): void
{
    $this->uploadedContractDocumentPath = $path;
}

public function hasUploadedContract(): bool
{
    return null !== $this->uploadedContractDocumentPath;
}
```

**Migration**: `doctrine:migrations:diff` to generate the column addition.

### 9. Fix `CustomerSigningController` — BANK_TRANSFER redirect

`src/Controller/Public/CustomerSigningController.php:128-132`:

**Before:**
```php
if (PaymentMethod::GOPAY === $order->paymentMethod) {
    return $this->redirectToRoute('public_order_payment', ['id' => $order->id]);
}
return $this->redirectToRoute('public_customer_signing_complete', ['id' => $order->id]);
```

**After:**
```php
if (PaymentMethod::EXTERNAL === $order->paymentMethod) {
    return $this->redirectToRoute('public_customer_signing_complete', ['id' => $order->id]);
}
// GOPAY and BANK_TRANSFER both go to payment page
return $this->redirectToRoute('public_order_payment', ['id' => $order->id]);
```

### 10. Fix `CustomerSignOnboardingHandler` — BANK_TRANSFER branch

`src/Command/CustomerSignOnboardingHandler.php:66-67`:

**Before:**
```php
if (PaymentMethod::EXTERNAL === $order->paymentMethod) {
    $this->orderService->confirmPayment($order, $now);
    $this->commandBus->dispatch(new CompleteOrderCommand(order: $order));
}
// For GOPAY: order stays in RESERVED state
```

**After:**
```php
if (PaymentMethod::EXTERNAL === $order->paymentMethod) {
    $this->orderService->confirmPayment($order, $now);
    $this->commandBus->dispatch(new CompleteOrderCommand(order: $order));
}
// GOPAY and BANK_TRANSFER: order stays in RESERVED, customer proceeds to payment page
```

No code change needed — the existing fallthrough already handles BANK_TRANSFER correctly (stays RESERVED). Just add a comment for clarity.

### 11. Update signing page template — contract-document-aware

`templates/public/customer_signing.html.twig`:

When `order.hasUploadedContract()` is true:
- **Hide** the contract content/preview section
- **Hide** the `accept_contract` checkbox
- **Show** a green info banner: "Vaše smlouva byla nahrána administrátorem. Níže prosím odsouhlaste obchodní podmínky a potvrďte podpisem."
- **Keep** VOP content + `accept_vop` checkbox
- **Keep** GDPR checkbox
- **Keep** signature canvas (for VOP signing)
- **Keep** signing place field + signature consent checkbox

`CustomerSigningController::handlePost()` — make `accept_contract` validation conditional:
```php
if (!$order->hasUploadedContract() && !$accepted) {
    $errors[] = 'Pro pokračování je nutné souhlasit se smluvními podmínkami.';
}
```

### 12. Propagate uploaded contract on order completion

`src/Service/OrderService.php::completeOrder()`:

After creating the contract, check if the order has an uploaded contract document:

```php
$contract = new Contract(/* ... */);

// Propagate uploaded paper contract from admin onboarding
if (null !== $order->uploadedContractDocumentPath) {
    $contract->attachDocument($order->uploadedContractDocumentPath, $now);
}
```

This ensures the paper contract PDF is attached to the `Contract` entity and included in customer emails via `OrderEmailAttachmentsService` (which falls back to `Contract.documentPath` when `Order.hasSignature()` produces a paper-only order — this path already works per spec 043).

### 13. Update order status page — payment CTA

`templates/public/order_status.html.twig`:

When the order is in a payable state (RESERVED or ACCEPTED, with signature + terms accepted), and `paymentMethod` is GOPAY or BANK_TRANSFER, show a prominent CTA:

```twig
{% if order.canBePaid() and order.hasSignature() and order.hasAcceptedTerms() %}
    <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 mb-6">
        <h3 class="font-semibold text-amber-800 mb-2">Čeká na platbu</h3>
        <p class="text-amber-700 text-sm mb-3">
            {% if order.paymentMethod.value == 'bank_transfer' %}
                Vaše objednávka čeká na přijetí bankovního převodu.
            {% else %}
                Pro dokončení objednávky je třeba provést platbu.
            {% endif %}
        </p>
        <a href="{{ path('public_order_payment', {id: order.id}) }}"
           class="btn btn-primary">
            {% if order.paymentMethod.value == 'bank_transfer' %}
                Zobrazit platební údaje
            {% else %}
                Zaplatit
            {% endif %}
        </a>
    </div>
{% endif %}
```

### 14. Update `AdminOnboardingController`

Replace the two-card index page with the unified form:

```php
#[Route('/portal/admin/onboarding', name: 'admin_onboarding')]
final class AdminOnboardingController extends AbstractController
{
    public function __invoke(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        return $this->render('admin/onboarding/index.html.twig');
    }
}
```

Remove the two old routes (`admin_onboarding_digital`, `admin_onboarding_migrate`) and their controllers.

### 15. Delete old routes from everywhere

Grep for `admin_onboarding_digital` and `admin_onboarding_migrate` across templates, controllers, and tests. Update all references to point to `admin_onboarding` (single route).

### 16. Tests

**Delete:**
- `tests/Unit/Form/AdminCreateOnboardingFormDataTest.php`
- `tests/Unit/Command/AdminMigrateCustomerHandlerTest.php`
- `tests/Integration/Controller/Admin/AdminCreateOnboardingControllerTest.php`

**Create / rewrite:**

1. **`tests/Unit/Form/AdminOnboardingFormDataTest.php`** — validate all constraint callbacks:
   - Company info required when `invoiceToCompany = true`
   - Birth date 18+ check
   - Date validations (7-day min for LIMITED, 1-year max)
   - BANK_TRANSFER forces MANUAL_RECURRING
   - UNLIMITED + non-BANK_TRANSFER forces AUTO_RECURRING
   - YEARLY forces MANUAL_RECURRING
   - External + non-free requires paidThroughDate
   - Contract document MIME + size validation
   - Variable symbol numeric + length

2. **`tests/Unit/Command/AdminOnboardingHandlerTest.php`** — test all handler branches:
   - New user creation vs existing user update
   - GOPAY path: signing token set, event dispatched
   - BANK_TRANSFER: variable symbol generated (no override)
   - BANK_TRANSFER: variable symbol override used
   - EXTERNAL: forced for free/prepaid
   - Contract document path propagated to order
   - BillingMode + PaymentFrequency propagated

3. **`tests/Integration/Controller/Admin/AdminOnboardingControllerTest.php`** — smoke test:
   - Page loads with 200 for ROLE_ADMIN
   - 403 for non-admin

4. **`tests/Unit/Command/CustomerSignOnboardingHandlerTest.php`** — update existing:
   - Add test: BANK_TRANSFER order stays RESERVED after signing (not auto-completed)

5. **`tests/Integration/Controller/CustomerSigningControllerTest.php`** — update existing:
   - Add test: BANK_TRANSFER order redirects to payment page after signing
   - Add test: uploaded contract hides contract section, keeps VOP + signature

6. **`tests/Unit/Twig/Components/AdminOnboardingFormTest.php`** (if feasible):
   - `getStoragesJson()` returns only available storages
   - Place change resets storageType + storage
   - StorageType change resets storage

**Update existing tests** that reference deleted classes/routes:
- `tests/Integration/Twig/Components/OnboardingBannerRenderingTest.php` — if it references old onboarding routes
- `tests/Integration/Event/SendOrderPlacedEmailHandlerTest.php` — if it uses old command fixtures
- Grep for `AdminCreateOnboarding` and `AdminMigrateCustomer` across all test files

### 17. Update fixtures

`fixtures/OnboardingFixtures.php` currently uses `AdminCreateOnboardingHandler` logic inline (creating orders directly). This doesn't need to change — fixtures create orders directly via entity methods, not through the old handlers. But verify the fixtures still work after the old commands are deleted (they shouldn't depend on them).

### 18. Cleanup

- Remove the `admin_onboarding_form_controller.js` Stimulus controller from `assets/controllers/` (if it exists — the old digital.html.twig referenced `data-controller="admin-onboarding-form"`). The new Live Component handles show/hide server-side.
- Update `PROJECT_MAP.md`: remove `admin_onboarding_digital` and `admin_onboarding_migrate` routes; update `AdminOnboardingController` description; add `AdminOnboardingForm` Live Component; add `AdminOnboardingCommand` + `Handler`.

## Acceptance

- [ ] `/portal/admin/onboarding` renders a single dynamic form (no more two-card index)
- [ ] Selecting a place dynamically loads storage types for that place
- [ ] Selecting a storage type shows the inline Konva map with only available storages (green) vs unavailable (grey) for the selected date range
- [ ] Clicking an occupied storage on the map does NOT select it (only available storages are selectable)
- [ ] Changing dates recalculates availability on the map
- [ ] Form submission creates an order with signing token + dispatches signing-link email
- [ ] Customer can sign VOP via `/podpis/{token}` and complete the flow
- [ ] When admin uploads a contract document: signing page shows VOP-only (no contract section) but still requires digital signature
- [ ] BANK_TRANSFER: after signing, customer is redirected to payment page (QR + bank details)
- [ ] GOPAY: after signing, customer is redirected to GoPay payment page
- [ ] EXTERNAL/FREE: after signing, order auto-completes
- [ ] Uploaded contract document is attached to the Contract entity after order completion
- [ ] Variable symbol: auto-generated for BANK_TRANSFER when empty, uses override when provided
- [ ] Order status page (`/stav`) shows "Zaplatit" / "Zobrazit platební údaje" CTA for signed-but-unpaid orders
- [ ] `composer quality` passes
- [ ] `composer test` passes (all 1100+ tests)
- [ ] No occupied storages appear as selectable in the form

## Out of scope

- **Admin edit/update of existing onboarding orders** — admin can only create new ones. If something is wrong, cancel and recreate.
- **Live address autocomplete on the admin form** — reuse the existing `address_autocomplete_controller.js` that's already wired via the `_address_override.html.twig` macro (spec 037). No new autocomplete work.
- **Admin-side instant-complete (skip customer signing)** — per business requirement, customer ALWAYS signs VOP. No backdoor.
- **Refactoring `CustomerSignOnboardingHandler` into `OrderService`** — the handler is already clean; just needs the BANK_TRANSFER comment. Don't move code.
- **Migrate flow's `totalPriceInCzk` + `paidAt` explicit historical payment recording** — dropped. External prepayment is handled by `isExternallyPrepaid` + `paidThroughDate`. For historical amounts, admin adjusts in Fakturoid directly.
- **Rewriting the `storage_map_controller.js`** — the existing controller works. We just need a new bridge to wire it to the admin Live Component.

## Open questions

None — proceed.
