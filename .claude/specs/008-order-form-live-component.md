# 008 — Order form as a Live Component (preserve inputs across map clicks, no HTML5 validation, live server validation)

**Status:** ready
**Type:** refactor (frontend wiring + Live Component extraction)
**Scope:** medium (~5 files: new component class + template, refactor page template + controller, tweak storage-map Stimulus controller)
**Depends on:** none. Compatible with 005 (password toggle), 006 (ARES lookup), 007 (flatpickr).

## Problem

On `/objednavka/{placeId}/{storageTypeId}/{storageId}` the user can click a storage on the map to switch their selection. Today that triggers `window.location.href = ...` (`assets/controllers/storage_map_controller.js:505`) — a full page reload. Until the user has hit "Rekapitulace" once and persisted form data to session (`OrderCreateController:134`), the reload **wipes every field they typed** (name, email, address, dates, etc.). Easy to lose 30 seconds of typing with one stray click.

A second smaller wart: the form runs HTML5 native validation in addition to server-side Symfony validation. On submit the browser pops its own (locale-mismatched) validation bubbles instead of letting our Czech-language server messages render. Errors only surface after a full POST — no per-field feedback while the user is filling things in.

## Goal

Wrap the order form in a Symfony UX Live Component. Map clicks update a `storageId` LiveProp via a custom DOM event — the form re-renders with the new selected storage but every other input the user already typed stays put. HTML5 client validation is disabled (`novalidate`); validation runs on the server and shows under each field as the user blurs out of it (live, per-field). Final submit (the "Rekapitulace" button) still goes through the existing `/objednavka/.../prijmout` flow with the **currently selected** storageId.

## Context (current state)

### Page composition
- `src/Controller/Public/OrderCreateController.php` (188 lines, single-action) does it all: validates UUIDs, finds entities, auto-redirects to first-available storage if `storageId` is null, hydrates `OrderFormData` from session OR user OR blank, builds the form with `createForm(OrderFormType::class)`, calls `handleRequest`, on success persists to session and redirects to `public_order_accept`. Also computes prices and serializes storage map data.
- `templates/public/order_create.html.twig` (467 lines) renders: breadcrumb, an Alpine.js block (`orderForm()`) that calculates dynamic pricing client-side, the form (lines 93–291), the order-summary sidebar, and the storage map (lines 418–465). The form posts to the same URL.
- `src/Form/OrderFormType.php` — fields: email, firstName, lastName, phone, birthDate, plainPassword, invoiceToCompany, companyId/Name/VatId/billing*, rentalType, startDate, endDate. After spec 007 every date field is wrapped by flatpickr via the form-theme override.
- `src/Form/OrderFormData.php` — `final` (not readonly), uses Symfony Validator + `#[Assert\Callback]` methods (validatePassword, validateAddress, validateCompanyInfo, validateBirthDate, validateDates). Contains `fromUser()`, `fromSessionArray()`, `toSessionArray()`.

### Storage map
- `assets/controllers/storage_map_controller.js`. The `orderBaseUrl` value (line 17) — set as `data-storage-map-order-base-url-value="{{ path('public_order_create', …) }}/__STORAGE_ID__"` in the template — is the toggle that puts the map into "order picker" mode. On click of an available, type-matching, non-currently-highlighted storage, line 505 does `window.location.href = this.orderBaseUrlValue.replace('__STORAGE_ID__', storage.id)`. Other consumers (`place_detail.html.twig`, `portal_browse_place_detail`) do **not** set `orderBaseUrl`; they fall through to the legacy modal/scroll behavior in lines 510–522.

### Live Component precedent
- `src/Twig/Components/BillingInfoForm.php` — the canonical example in this codebase. Uses `#[AsLiveComponent]`, `ComponentWithFormTrait`, `DefaultActionTrait`, extends `AbstractController`. `instantiateForm()` returns the Form. Rendered via `{{ component('BillingInfoForm', { form: form }) }}` from `templates/user/billing_info.html.twig:14`.
- `templates/components/BillingInfoForm.html.twig` — wraps `form_start … form_end` and re-uses the existing FormType verbatim.
- `importmap.php:47` — `@symfony/ux-live-component` is loaded from `vendor/.../live_controller.js`. `composer.json` requires `symfony/ux-live-component: ^2.34`.

### `novalidate` precedent
- `templates/admin/onboarding/digital.html.twig:19` and `templates/admin/onboarding/migrate.html.twig:19` already use `{{ form_start(form, {'attr': {'novalidate': 'novalidate'}}) }}`. Mirror that pattern.

### Session contract
- `OrderAcceptController:61` reads `$request->getSession()->get('order_form_data')`. On final submit the live component writes the same `order_form_data` shape via `OrderFormData::toSessionArray()` then redirects to `public_order_accept` with the current storageId. **Don't change this contract** — the acceptance controller stays untouched.

### Alpine.js client state
The page has `x-data="orderForm()"` (lines 17–75, 84–93) doing dynamic pricing math + show/hide for `invoiceToCompany` / `rentalType` blocks. UX Live Component re-renders use Idiomorph, so Alpine state on elements that survive the morph is preserved. We keep Alpine for the read-only price preview and show/hide UI — server doesn't need to know about it. The Alpine root element gets a stable `id` so morphing is unambiguous.

## Architecture

```
                [page render — OrderCreateController]
                              │
                              ▼
   templates/public/order_create.html.twig
   ├─ breadcrumb
   ├─ <div data-controller="order-map-bridge">                ← new tiny Stimulus glue
   │    ├─ {{ component('OrderForm', { ... }) }}             ← Live Component
   │    │    └─ form (novalidate) + fields + submit
   │    ├─ order-summary sidebar (static)
   │    └─ storage-map  (data-storage-map-select-mode-value="true")
   │       on click → CustomEvent('storage-map:select', { detail: { storageId } })
   │
   └─ Alpine root (#order-form-pricing) for price preview only
                              │
                              ▼
   order-map-bridge captures storage-map:select →
        emits live action selectStorage(storageId)
                              │
                              ▼
   OrderForm Live Component:
        LiveProp $storageId is updated → re-renders →
        sidebar storage info + flatpickr min-date stay consistent →
        form fields keep their typed values (LiveProps preserve input data)
```

On submit: a LiveAction `submit()` calls `submitForm()`, validates, on success writes `OrderFormData::toSessionArray()` to the session and returns a `RedirectResponse` to `public_order_accept` with the current `$this->storageId`.

## Requirements

### 1. New Live Component `App\Twig\Components\OrderForm`

Create `src/Twig/Components/OrderForm.php`. Mirror `BillingInfoForm` for shape; differences are the LiveProps for routing context and the action methods.

```php
<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\RentalType;
use App\Form\OrderFormData;
use App\Form\OrderFormType;
use App\Repository\StorageRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class OrderForm extends AbstractController
{
    use DefaultActionTrait;
    use ComponentWithFormTrait;

    #[LiveProp]
    public Place $place;

    #[LiveProp]
    public StorageType $storageType;

    #[LiveProp(writable: true)]
    public string $storageId = '';

    /** @var list<string> Field names the user has blurred — drives which errors render */
    #[LiveProp(writable: true)]
    public array $touchedFields = [];

    public function __construct(
        private readonly StorageRepository $storageRepository,
        private readonly RequestStack $requestStack,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /** @return FormInterface<OrderFormData> */
    protected function instantiateForm(): FormInterface
    {
        $session = $this->requestStack->getSession();
        $sessionData = $session->get('order_form_data');

        if (is_array($sessionData)) {
            $formData = OrderFormData::fromSessionArray($sessionData);
        } elseif (($user = $this->getUser()) instanceof User) {
            $formData = OrderFormData::fromUser($user);
        } else {
            $formData = new OrderFormData();
        }

        $formData->startDate ??= $this->calculateMinStartDate();

        return $this->createForm(OrderFormType::class, $formData);
    }

    public function getSelectedStorage(): Storage
    {
        $storage = $this->storageRepository->find(\Symfony\Component\Uid\Uuid::fromString($this->storageId));
        if (null === $storage) {
            throw $this->createNotFoundException('Skladová jednotka nenalezena.');
        }

        return $storage;
    }

    #[LiveAction]
    public function selectStorage(#[LiveArg] string $storageId): void
    {
        if (!\Symfony\Component\Uid\Uuid::isValid($storageId)) {
            return;
        }

        $candidate = $this->storageRepository->find(\Symfony\Component\Uid\Uuid::fromString($storageId));
        if (null === $candidate) {
            return;
        }
        if (!$candidate->place->id->equals($this->place->id)) {
            return;
        }
        if (!$candidate->storageType->id->equals($this->storageType->id)) {
            return;
        }
        if (!$candidate->isAvailable()) {
            return;
        }

        $this->storageId = $storageId;
    }

    #[LiveAction]
    public function touchField(#[LiveArg] string $field): void
    {
        if (!in_array($field, $this->touchedFields, true)) {
            $this->touchedFields[] = $field;
        }
        // Re-bind current form values so errors render with up-to-date data.
        $this->getForm()->submit($this->formValues, false);
    }

    #[LiveAction]
    public function submit(): RedirectResponse
    {
        $this->submitForm(); // throws UnprocessableEntityHttpException if invalid → re-renders with errors

        /** @var OrderFormData $data */
        $data = $this->getForm()->getData();

        if (RentalType::UNLIMITED === $data->rentalType) {
            $data->endDate = null;
        }

        $this->requestStack->getSession()->set('order_form_data', $data->toSessionArray());

        return new RedirectResponse($this->urlGenerator->generate('public_order_accept', [
            'placeId' => $this->place->id->toRfc4122(),
            'storageTypeId' => $this->storageType->id->toRfc4122(),
            'storageId' => $this->storageId,
        ]));
    }

    private function calculateMinStartDate(): \DateTimeImmutable
    {
        $minDate = new \DateTimeImmutable('tomorrow');
        if ($this->place->daysInAdvance > 0) {
            $minDate = $minDate->modify('+'.$this->place->daysInAdvance.' days');
        }

        return $minDate;
    }
}
```

Notes:
- `LiveProp(writable: true)` on `$storageId` lets the action mutate it; the validator inside `selectStorage()` is the safety boundary (a hostile client can't switch to a storage from another place/type or unavailable one).
- `touchField` is the "live validation" trigger — see Requirement 5.
- `submitForm()` is from `ComponentWithFormTrait`. On invalid form it throws and Live Component re-renders the form with errors automatically.

### 2. New component template `templates/components/OrderForm.html.twig`

Move the form section currently in `templates/public/order_create.html.twig` lines 93–291 into this new template. Adjustments:

- Wrap in `<div {{ attributes }}>` (Live Component requirement so morphing has a root).
- `form_start` opts into `novalidate` and gets a stable id:

```twig
{{ form_start(form, {attr: {
    'novalidate': 'novalidate',
    'class': 'space-y-8',
    'data-action': 'submit->live#action:prevent',
    'data-live-action-param': 'submit',
    'id': 'order-form',
}}) }}
```

The `data-action` + `data-live-action-param` route the form's submit through the Live Component's `submit()` action instead of a normal POST.

- Each form widget gets a blur-fired live action so the field becomes "touched" and its server validation renders. Symfony Form lets per-widget attrs come from the FormType, but it's simpler to declare them at render time once. Use a Twig macro or pass attrs per-row, e.g.:

```twig
{% set blurValidate = {
    'data-action': 'blur->live#action',
    'data-live-action-param': 'touchField',
} %}

{{ form_row(form.firstName, {attr: blurValidate|merge({'data-live-action-field-param': 'firstName'})}) }}
```

…repeat for every field. (Yes, repetitive — but explicit and grep-able. A small Twig macro `{% macro blurField(form, field) %}` in the same file is fine if the dev prefers.)

- Conditionally hide each field's error block when the field hasn't been touched yet. The default `form_errors(form.X)` always renders; override at the row level:

```twig
{% if 'firstName' in this.touchedFields %}
    {{ form_errors(form.firstName) }}
{% endif %}
```

(`this` inside a Live Component template refers to the component instance — its public/LiveProp properties are accessible.)

The "submit" error display — when the user clicks "Rekapitulace" with invalid input — should populate `touchedFields` with every field name so all errors render. Easiest: in the component, on `submit()` failure, push every field key to `touchedFields` before re-throwing/letting it re-render. Actually `submitForm()` throws after validation fails — wrap it:

```php
#[LiveAction]
public function submit(): RedirectResponse
{
    try {
        $this->submitForm();
    } catch (\Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException $e) {
        $this->touchedFields = array_keys($this->getForm()->all());
        throw $e;
    }
    // … success path as above
}
```

- The ARES lookup wrapper (`<div data-controller="ares-lookup">…</div>` from the existing template at lines 137–201) lives inside the component template unchanged. ARES writes input values via `dispatchEvent(new Event('input'))` — that triggers Live's `data-model` change detection (if the inputs use `data-model`) or, since we're using blur-touch validation, no change is needed. The values are submitted as part of the next form re-bind.

- The dynamic pricing Alpine block (`x-data="orderForm()"`, lines 17–75 + 84–93) **stays** but moves to the **page template** (Requirement 4) — not into the component — because pricing is read-only and depends on values that are already in the form HTML; Alpine reads from the rendered inputs and that round-trips fine.

### 3. Map → component bridge

#### 3a. `assets/controllers/storage_map_controller.js`

Replace the `orderBaseUrl` mode (lines 17, 465, 503–508) with a "select-mode" dispatching a CustomEvent:

```js
// at the top with other static values
static values = {
    // … existing values
    selectMode: { type: Boolean, default: false },
}

// in the click handler (around current line 499)
group.on('click tap', () => {
    if (this.isPanning) return;

    if (this.selectModeValue) {
        if (isClickable) {
            this.element.dispatchEvent(new CustomEvent('storage-map:select', {
                detail: { storageId: storage.id },
                bubbles: true,
            }));
        }
        return;
    }

    // Legacy: place detail behavior
    // (existing branch from current line 510 onwards stays as-is)
});
```

Update the clickability check — replace the line that uses `hasOrderBaseUrlValue`:

```js
const isClickable = this.selectModeValue
    ? storage.status === 'available'
        && storage.storageTypeId === this.currentStorageTypeIdValue
        && storage.id !== this.highlightStorageValue
    : storage.status === 'available';
```

Drop `orderBaseUrl: String` from `static values`. Remove the `replace('__STORAGE_ID__', …)` line entirely.

The CustomEvent `bubbles: true` lets a listener anywhere up the tree catch it.

#### 3b. Page template wiring

In `templates/public/order_create.html.twig`, where the storage map `<div>` is currently rendered (lines 418–465), change the data-attributes:

```twig
<div class="card mt-8"
     data-controller="storage-map"
     data-storage-map-map-image-value="{{ place.mapImagePath ? asset('uploads/' ~ place.mapImagePath) : '' }}"
     data-storage-map-storages-value="{{ storagesJson|e('html_attr') }}"
     data-storage-map-place-id-value="{{ place.id }}"
     data-storage-map-current-storage-type-id-value="{{ storageType.id }}"
     data-storage-map-select-mode-value="true"
     data-storage-map-highlight-storage-value="{{ selectedStorageId }}">
```

Drop `data-storage-map-order-base-url-value`. The `highlight` value is now driven by the Live Component's current `storageId` — the page passes it on initial render; afterwards the map is **outside** the live component, so its highlight needs to update via a tiny Stimulus controller (Requirement 3c).

#### 3c. New `assets/controllers/order_map_bridge_controller.js`

A 20-line glue Stimulus controller wrapping both the live component and the map. It:
- Listens for `storage-map:select` events bubbling from the map.
- Calls the live component's `selectStorage` action with the storageId from the event detail.
- Listens for the live component's re-render (Live emits `live:render` events on its root) and updates the map controller's `highlightStorage` value to keep visuals in sync.

```js
import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';

export default class extends Controller {
    static targets = ['liveForm', 'map'];

    async selectStorage(event) {
        const component = await getComponent(this.liveFormTarget);
        await component.action('selectStorage', { storageId: event.detail.storageId });
    }

    syncHighlight() {
        // Pull current storageId from the live component's data-live-props-value JSON
        const propsAttr = this.liveFormTarget.getAttribute('data-live-props-value');
        if (!propsAttr) return;
        try {
            const props = JSON.parse(propsAttr);
            if (props.storageId) {
                this.mapTarget.setAttribute('data-storage-map-highlight-storage-value', props.storageId);
            }
        } catch {}
    }
}
```

Wire it in the page template:

```twig
<div data-controller="order-map-bridge"
     data-action="storage-map:select->order-map-bridge#selectStorage live:render->order-map-bridge#syncHighlight">
    <div data-order-map-bridge-target="liveForm">
        {{ component('OrderForm', { place: place, storageType: storageType, storageId: preSelectedStorage.id.toRfc4122() }) }}
    </div>
    <!-- sidebar -->
    <div data-order-map-bridge-target="map" class="card mt-8"
         data-controller="storage-map"
         data-storage-map-select-mode-value="true"
         …>
        …
    </div>
</div>
```

(The exact DOM structure can shuffle to fit the `lg:grid-cols-3` layout that's already there; what matters is that both targets are reachable from the bridge controller's element.)

### 4. Refactor `templates/public/order_create.html.twig`

- **Keep**: breadcrumb (lines 7–15), Alpine `orderForm()` script block (lines 17–75) — but the Alpine root moves to wrap **only the price preview + show/hide blocks** that read from form inputs. The Alpine root needs `id="order-form-pricing"` (stable id for morphing).
- **Replace** the inner form area (lines 84–292) with `{{ component('OrderForm', { … }) }}`.
- **Keep** the order-summary sidebar (lines 297–414) and the storage map (lines 417–465), with the map-attribute changes from Req 3b.
- The page template no longer needs `'form' => $form` from the controller — drop that variable.
- The summary sidebar references `preSelectedStorage` for photos (line 343). After the refactor, the page template still receives `preSelectedStorage` from the controller for the **initial** render. On subsequent map clicks, the sidebar storage info **can become stale** unless we also re-render it inside the component; the simplest scope-cut: render the storage sidebar **inside** the component too, so it updates in lockstep. (Slight reshuffle of the grid columns; absorb into the component template.)
  - Decision: **move the sidebar into the component template** so it always reflects the currently selected storage. Photos, prices, dimensions all update on map click. The breadcrumb + Alpine pricing + map stay outside.

### 5. Live validation behavior

- **Cadence**: validation runs **on field blur**, not per-keystroke. Cleaner UX, lighter server traffic, and aligns with how flatpickr/ARES dispatch their input events. Per-keystroke can be a follow-up if requested.
- **Per-field**: the `touchField` LiveAction is fired by `data-action="blur->live#action"` + `data-live-action-param="touchField"` + `data-live-action-field-param="<fieldName>"` on each input (or a Twig macro that emits those attrs).
- **Untouched-field errors**: hidden via `{% if 'fieldName' in this.touchedFields %}` around `{{ form_errors(form.fieldName) }}`.
- **Submit-button clicked with invalid form**: `submit()` LiveAction populates `touchedFields` with every field name (so all errors render), Live Component re-renders, no redirect.
- **HTML5 validation**: suppressed via `novalidate` on form_start.

### 6. Controller cleanup

In `src/Controller/Public/OrderCreateController.php`:

- Remove `createForm`, `handleRequest`, the `$form->isSubmitted() && $form->isValid()` block, the session-write, and the redirect-to-acceptance. The Live Component's `submit()` LiveAction now owns that.
- Keep: UUID validation, place + storageType + storage lookup, the auto-redirect-to-first-available branch, the unavailable-storage fallback, the storages-for-map serialization.
- Render variables shrink to: `storageType`, `place`, `weeklyPrice`, `monthlyPrice`, `minStartDate`, `preSelectedStorage`, `storagesJson`. Drop `'form' => $form` and `'highlightStorageId'` (the component now owns the selected storageId; the highlight is the same value).

The controller stays a single-action `__invoke` controller per project conventions.

### 7. Tests

- Adapt or skip-then-rewrite any feature test for the order-create page. There are no existing tests for `OrderCreateController` (`find tests -name '*OrderCreate*'` empty), so no breakage.
- Add `tests/Integration/Twig/Components/OrderFormTest.php` using Symfony UX's `InteractsWithLiveComponents` trait:
  - Render component with valid place/storageType/storageId — assert form renders.
  - Call `selectStorage` action with another available storage of the same type — assert `$component->storageId` changed.
  - Call `selectStorage` action with a storage from a *different place* — assert `$component->storageId` unchanged (boundary).
  - Call `selectStorage` action with an unavailable storage — assert `$component->storageId` unchanged.
  - Call `touchField('firstName')` then assert `'firstName'` is in `touchedFields`.
  - Submit with invalid data (empty email) — assert the response is NOT a `RedirectResponse` and `touchedFields` includes `email`.
  - Submit with valid data — assert response is a `RedirectResponse` to `public_order_accept` with the right `storageId` query param, and `order_form_data` is set in the session.

(`InteractsWithLiveComponents` from `symfony/ux-live-component/tests` lets you `createLiveComponent(...)` and call `$component->call('actionName', $args)` directly. See UX docs.)

## Acceptance

- `docker compose exec web composer quality` is green.
- Manual flow — open `/objednavka/{placeId}/{storageTypeId}/{storageId}` as a guest:
  - Type into firstName, lastName, email, phone, birthDate, address fields.
  - Click a different available storage on the map.
  - **Every field keeps its typed value.** The sidebar updates to show the new storage's photos/dimensions/prices.
  - Browser network tab shows a Live Component XHR (no full page reload).
- Click submit on an empty form:
  - Browser does NOT show its own native validation popups (no "Please fill out this field" tooltips).
  - Server-side error messages (Czech, with diacritics) appear under each invalid field.
- Type something into firstName, blur out:
  - The "Zadejte jméno." server error is removed (or another applicable error renders) — proving per-field validation runs on blur.
- Untouched fields don't show errors until the user either touches them or attempts to submit.
- Pick valid values and click "Rekapitulace": the page redirects to `/objednavka/{placeId}/{storageTypeId}/{storageId}/prijmout` with the **currently selected** storageId (the one chosen via the map, not the URL's original).
- Click submit with invalid data: every field's error renders simultaneously (since `touchedFields` was filled by the `submit()` action).
- Pick a date in the start-date or end-date flatpickr — dynamic price preview (Alpine) still updates.
- Type an IČO, click "Načíst z ARES" — fields populate as before; no console errors.
- Reload the page mid-edit: form data is lost (expected; we don't persist mid-edit to session — only on full submit).

## Out of scope

- **Per-keystroke validation.** On-blur is enough; per-keystroke is louder and rate-limits worse. Easy follow-up if the user asks.
- **Updating the URL via `history.replaceState()` when `storageId` changes.** Submit goes through the LiveAction with the right storageId, so the URL being stale doesn't break anything functional. Bookmarking the mid-edit URL is a separate, lower-priority polish.
- **Persisting mid-edit form data to session** (drafts that survive accidental reloads). Different scope; would need a periodic LiveAction or localStorage adapter.
- **Replacing Alpine.js with all-server-rendered pricing.** The Alpine block is read-only and stable through morphing; no need to churn it.
- **Applying the same Live Component treatment to admin onboarding/migrate forms** (`AdminCreateOnboardingFormType`, `AdminMigrateCustomerFormType`). They're admin-only, no map, no input-loss problem.
- **Removing `OrderFormData::toSessionArray() / fromSessionArray()`.** The acceptance flow still reads from the session; we keep that contract.
- **Touching `OrderAcceptController`.** Untouched.
- **Expanding the storage map's "select-mode" to other pages.** Only the order create page sets `select-mode-value="true"`; place detail keeps legacy click behavior.
- **CSRF on the LiveAction submit.** UX Live Component handles its own CSRF token automatically; no extra config.

## Open questions

None — proceed.
