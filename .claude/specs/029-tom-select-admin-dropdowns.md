# 029 — Searchable, grouped admin dropdowns (TomSelect rollout)

**Status:** done
**Type:** UX (frontend) + small backend choice-builder refactor
**Scope:** medium (~14 files: importmap + 1 Stimulus controller + 1 CSS file + 1 form theme tweak + 4 FormType edits + 1 small choice-builder service + 5 template touches for raw `<select>` filters)
**Depends on:** none

## Problem

Admin / landlord selects that pick a `Storage`, `StorageType` or `Place` are flat alphabetised dumps of the entire fleet — no grouping, no search, no diacritic-folding. Concretely:

- `AdminCreateOnboardingFormType.storageId` and `AdminMigrateCustomerFormType.storageId` render every available storage from every place as one long `Place – Type (Number)` list, ordered only by `s.number ASC` (`StorageRepository::findAllAvailable()`). Numbers like "A1, A10, A2" intermix because of natural-sort issues, and storages from different places sit next to each other when their numbers happen to match.
- `StorageFormType.placeId` calls `placeRepository->findAll()` with **no `ORDER BY`** — the dropdown order is whatever Postgres returns, which drifts.
- `StorageFormType.storageTypeId` (create-mode) calls `findAllActive()` and labels each with `name (dimensions) - place.name`. Types from different places are mixed together; there is no `<optgroup>` grouping.
- `StorageUnavailabilityFormType.storageId` for admins calls `findAll()` with no order and dumps the whole platform.
- Raw `<select>` filters on `/portal/admin/audit-log` (entity_type, event_type), `/portal/admin/email-log` (template, status), `/portal/calendar` (place, storage_type), and `/portal/storages` (storage_type) have no search at all, only native `<select>` keyboard cycling.

Result: admins routinely scroll through 100+ options and ten places. Picking the wrong storage in onboarding is a common minor incident.

## Goal

Every dropdown listed above becomes:
1. **Searchable** — type a few letters, including diacritic folding (typing `praha` matches "Praha", typing `misto` matches "místo").
2. **Grouped** — storages and storage-types use `<optgroup>` so the list is mentally chunked by place / storage type.
3. **Stably ordered** — places by name, storage types by name within a place, storages by natural-sorted `number` within a `(place, type)` group.
4. **Visually consistent** with our existing `.form-input` styling (no jarring third-party theme).
5. **Opt-in** — `data-controller="tom-select"` on the `<select>`. Native `<select>` remains the default everywhere else (consumer-facing register/checkout flows untouched).

## Context (current state)

- Stack: Symfony 8, Asset Mapper + importmap (`importmap.php`), Stimulus 3.2.2 auto-registered via `@symfony/stimulus-bundle`, no build step. Existing controllers in `assets/controllers/` (`datepicker_controller.js`, `password_toggle_controller.js`, …) — same pattern applies here.
- Form theme: `templates/form/tailwind_theme.html.twig` already overrides `choice_widget_collapsed` (line 87–107). It keeps the parent `choice_widget_options` block, which **already supports** `<optgroup>` automatically when you pass `'choices' => ['Group label' => ['Option label' => 'value', …]]` (Symfony Form built-in). No theme change is strictly required for groups; only a small touch to forward `data-controller` from `attr` (already passes through `block('widget_attributes')`).
- Affected FormTypes (verified file paths):
  - `src/Form/AdminCreateOnboardingFormType.php:91` — `storageId` ChoiceType, choices from `StorageRepository::findAllAvailable()` (line 220, ORDER BY `s.number ASC` only).
  - `src/Form/AdminMigrateCustomerFormType.php:91` — identical to above; same flat label `place.name - storageType.name (number)`.
  - `src/Form/StorageFormType.php:45` — `placeId` ChoiceType, choices from `placeRepository->findAll()` **with no order**. Line 56 — `storageTypeId` ChoiceType, two choice builders depending on `is_edit`.
  - `src/Form/StorageUnavailabilityFormType.php:33` — `storageId` ChoiceType; for admins `storageRepository->findAll()` with no order, for landlords `findByOwner()` ordered by `s.number ASC` only.
- Affected raw-select templates (no FormType — they're plain HTML filter selects):
  - `templates/admin/audit_log/list.html.twig:16` (entity_type), `:25` (event_type) — options come from `AuditLogRepository::getDistinctEntityTypes()` / `getDistinctEventTypes()` (raw class FQCNs and dot-event-names).
  - `templates/admin/email_log/list.html.twig:36` (template), `:45` (status).
  - `templates/portal/calendar/index.html.twig:17` (place), `:29` (storage_type) — both currently auto-submit on change.
  - `templates/portal/storage/list.html.twig:112` (storage_type) — auto-submits on change.
  - `templates/portal/storage/canvas.html.twig:239` — JS-driven `<select>` inside the canvas edit drawer (`data-storage-canvas-target="typeSelect"`). One place's types only, no autosubmit. **Skip** — single-place canvas already has 3-10 types max and the existing storage-canvas controller writes/reads `.value` directly, so wrapping it in TomSelect would require touching `assets/controllers/storage_canvas_controller.js`. Out of scope (see below).
- Library: TomSelect 2.x (`https://www.jsdelivr.com/package/npm/tom-select`). MIT, ~50KB min+gzip, no jQuery, native `<select>` behind it (so server submits exactly the same payload as today). Built-in `diacritics` plugin handles Czech accents transparently.
- Czech UI text uses full diacritics throughout (CLAUDE.md rule).
- Conventions: `final readonly` for DTOs, single-action controllers, FormType private helpers for choice builders are already the project pattern (see `StorageFormType::getStorageTypeChoices()` etc.).

## Architecture

```
[<select data-controller="tom-select" ...>]
        │
        ▼
tom_select_controller.js
   ├─ on connect(): new TomSelect(this.element, { options below })
   ├─ on disconnect(): destroy() so Live Component / Turbo morphing leaves no orphan DOM
   │
   └─ TomSelect renders a contenteditable wrapper around the native <select>
       and filters across <option> + <optgroup label>, diacritic-folded.
```

Server-side: each affected FormType / controller produces choices grouped exactly as the dropdown should look. PHP builds the `<optgroup>` structure via Symfony's nested-array `choices` shape — TomSelect picks up groups for free.

## Requirements

### 1. Add TomSelect to importmap

In `importmap.php`, add three entries (alongside `flatpickr`, `glightbox`, etc.):

```php
'tom-select' => [
    'version' => '2.4.1',
],
'tom-select/dist/css/tom-select.min.css' => [
    'version' => '2.4.1',
    'type' => 'css',
],
```

Run `docker compose exec web bin/console importmap:install` to vendor the assets into `assets/vendor/tom-select/`. Verify the CSS is exposed via `debug:asset-map | grep tom-select`.

In `assets/app.js`, add `import 'tom-select/dist/css/tom-select.min.css';` near the GLightbox / Flatpickr CSS imports so the styles ship with the main entry point. The JS itself is loaded by the Stimulus controller (Requirement 2).

### 2. Stimulus controller `tom-select`

Create `assets/controllers/tom_select_controller.js`:

```js
import { Controller } from '@hotwired/stimulus';
import TomSelect from 'tom-select';

export default class extends Controller {
    connect() {
        this.tomSelect = new TomSelect(this.element, {
            plugins: ['dropdown_input'],
            // diacritics is on by default in tom-select v2 — typing "misto" matches "místo".
            create: false,
            allowEmptyOption: true,
            maxOptions: 1000,
            // Keep native <option> ordering — we sort server-side by place → type → number.
            sortField: null,
            // Search across the visible label and the optgroup label so the user can type a place name.
            searchField: ['text', 'optgroup'],
            // Auto-submit support: if the underlying <select> has `data-tom-select-autosubmit-value`,
            // closest form is submitted on change (replaces the existing `onchange="this.form.submit()"`).
            onChange: () => {
                if (this.element.dataset.tomSelectAutosubmitValue === 'true') {
                    this.element.form?.requestSubmit();
                }
            },
        });
    }

    disconnect() {
        this.tomSelect?.destroy();
        this.tomSelect = null;
    }
}
```

Notes:
- `dropdown_input` plugin places the search input inside the dropdown panel (better mobile UX than the default inline-input mode for long lists).
- `searchField: ['text', 'optgroup']` is the key UX win for grouped storage selects: typing "Brno" filters down to the Brno place, even though "Brno" is the optgroup label, not part of any option label.
- `disconnect()` matters because Symfony UX Live Component morphing or Turbo navigations can yank the `<select>` mid-flight — without a destroy, TomSelect leaves a detached wrapper in the DOM.
- No special form-theme tweak needed: TomSelect inherits the underlying `<select>`'s `name`, `id`, `required`, and `disabled`, and submits the original element. The form submission stays byte-for-byte identical.

### 3. Form theme tweak — pass `data-controller` through `choice_widget_collapsed`

In `templates/form/tailwind_theme.html.twig`, the existing `choice_widget_collapsed` block already passes `attr` through `block('widget_attributes')`, which already emits `data-*` attributes set via `'attr' => ['data-controller' => 'tom-select']` on the FormType. **No theme change required.** Verify by adding `data-controller` via FormType `'attr'` option and confirming the rendered `<select>` has the attribute.

(Listed here so the dev doesn't go hunting for a theme override that isn't needed.)

### 4. New service: `App\Service\Form\StorageChoiceBuilder`

Create `src/Service/Form/StorageChoiceBuilder.php`:

```php
final readonly class StorageChoiceBuilder
{
    public function __construct(
        private StorageRepository $storageRepository,
    ) {}

    /**
     * Returns choices keyed by "Place name — Storage type name" optgroup label;
     * inner array maps "{number}" → storage UUID rfc4122 string.
     *
     * Used by AdminCreateOnboardingFormType and AdminMigrateCustomerFormType.
     *
     * @return array<string, array<string, string>>
     */
    public function buildAvailableGroupedChoices(): array
    {
        $storages = $this->storageRepository->findAllAvailable();

        return $this->groupAndSort($storages);
    }

    /**
     * Same shape, but for unavailability admin form (all storages, not just available).
     *
     * @param Storage[] $storages
     * @return array<string, array<string, string>>
     */
    public function groupAndSort(array $storages): array
    {
        $grouped = [];
        foreach ($storages as $storage) {
            $groupLabel = $storage->place->name.' — '.$storage->storageType->name;
            $grouped[$groupLabel][$storage->number] = $storage->id->toRfc4122();
        }

        // Sort optgroups by Czech-aware place+type label, options by natural sort on storage number.
        uksort($grouped, static fn (string $a, string $b): int => strnatcasecmp($a, $b));
        foreach ($grouped as $groupLabel => $opts) {
            uksort($opts, static fn (string $a, string $b): int => strnatcasecmp($a, $b));
            $grouped[$groupLabel] = $opts;
        }

        return $grouped;
    }
}
```

Symfony Form interprets a nested array as `<optgroup label="…"><option value="…">…</option></optgroup>` automatically when passed to `'choices'`.

### 5. Refactor `AdminCreateOnboardingFormType` + `AdminMigrateCustomerFormType`

Replace the inline `getStorageChoices()` helpers (each currently produces a flat `place - type (number)` label) with the new builder.

```php
// Constructor: replace StorageRepository injection with StorageChoiceBuilder.
public function __construct(
    private readonly StorageChoiceBuilder $storageChoiceBuilder,
) {}

// In buildForm():
->add('storageId', ChoiceType::class, [
    'label' => 'Skladová jednotka',
    'choices' => $this->storageChoiceBuilder->buildAvailableGroupedChoices(),
    'placeholder' => '-- Vyberte skladovou jednotku --',
    'attr' => ['data-controller' => 'tom-select'],
])
```

Delete the now-unused private `getStorageChoices()` method in both files.

### 6. Refactor `StorageUnavailabilityFormType`

Same pattern, but the input list depends on the user's role (admins see all, landlords see their own). Reuse `StorageChoiceBuilder::groupAndSort()`:

```php
$storages = $this->authorizationChecker->isGranted('ROLE_ADMIN')
    ? $this->storageRepository->findAll()
    : $this->storageRepository->findByOwner($user);

$builder->add('storageId', ChoiceType::class, [
    'label' => 'Sklad',
    'choices' => $this->storageChoiceBuilder->groupAndSort($storages),
    'placeholder' => '-- Vyberte sklad --',
    'attr' => ['data-controller' => 'tom-select'],
]);
```

Inject `StorageChoiceBuilder` instead of using inline grouping. Keep `StorageRepository` since `findAll()` / `findByOwner()` are still called here.

### 7. Refactor `StorageFormType` (place + storage-type pickers)

For `placeId` (only present on create): keep flat (one place per row), but add explicit ordering and TomSelect:

```php
$builder->add('placeId', ChoiceType::class, [
    'label' => 'Místo',
    'choices' => $this->getPlaceChoices(),
    'placeholder' => '-- Vyberte místo --',
    'attr' => ['data-controller' => 'tom-select'],
]);
```

In `getPlaceChoices()`: replace `findAll()` / `findByOwner()` with the same calls but **sort the resulting array by place name** (the repos do not order by name — `findAll()` has no order at all, `findByOwner()` doesn't apply). Use `usort` with `strnatcasecmp` on `$place->name`.

For `storageTypeId`:
- `getStorageTypeChoices()` (create flow, no place yet selected): group by `Place name`. Inner key = `"{type.name} ({type.dimensionsInMeters})"`, value = type UUID. Sort optgroups + inner items by name (`strnatcasecmp`).
- `getStorageTypeChoicesForPlace()` (edit flow): no grouping needed (single place), but sort by name.

Both pickers get `'attr' => ['data-controller' => 'tom-select']`.

```php
// rough sketch of getStorageTypeChoices() after refactor
private function getStorageTypeChoices(): array
{
    $storageTypes = $this->storageTypeRepository->findAllActive();

    $grouped = [];
    foreach ($storageTypes as $type) {
        $label = $type->name.' ('.$type->getDimensionsInMeters().')';
        $grouped[$type->place->name][$label] = $type->id->toRfc4122();
    }

    uksort($grouped, static fn (string $a, string $b): int => strnatcasecmp($a, $b));
    foreach ($grouped as $place => $items) {
        uksort($items, static fn (string $a, string $b): int => strnatcasecmp($a, $b));
        $grouped[$place] = $items;
    }

    return $grouped;
}
```

### 8. Raw `<select>` filters — sprinkle `data-controller="tom-select"`

Edit each template; just add the attribute. No PHP touches needed.

| Template | Lines | Change |
|---|---|---|
| `templates/admin/audit_log/list.html.twig` | 16, 25 | add `data-controller="tom-select"` to both `<select name="entity_type">` and `<select name="event_type">` |
| `templates/admin/email_log/list.html.twig` | 36, 45 | add `data-controller="tom-select"` to both `<select name="template">` and `<select name="status">` |
| `templates/portal/calendar/index.html.twig` | 17, 29 | add `data-controller="tom-select"` and `data-tom-select-autosubmit-value="true"`; **remove** the existing `onchange="this.form.submit()"` (the controller handles autosubmit, see Requirement 2) |
| `templates/portal/storage/list.html.twig` | 112 | same as calendar — add controller + autosubmit, remove `onchange` |

For the audit-log filters, also sort the `entityTypes` / `eventTypes` arrays alphabetically in the template via `|sort` (Twig built-in) before iterating — currently `getDistinctEntityTypes()` orders by ASC at the SQL level so it's already sorted, but verify and add `|sort` defensively if needed.

### 9. Dark-mode / Tailwind alignment

TomSelect's default CSS clashes mildly with our `.form-input` look. Add a small override at `assets/styles/tom-select-overrides.css`:

```css
/* Match .form-input height and border so TomSelect doesn't look pasted in */
.ts-wrapper.single .ts-control {
    @apply min-h-[42px] border border-gray-300 rounded-md px-3 py-2 text-sm shadow-sm;
}
.ts-wrapper.single.input-active .ts-control {
    @apply ring-1 ring-accent border-accent;
}
.ts-wrapper .ts-dropdown {
    @apply rounded-md shadow-lg border border-gray-200 mt-1 text-sm;
}
.ts-dropdown .optgroup-header {
    @apply bg-gray-50 text-gray-600 font-semibold px-3 py-1 text-xs uppercase tracking-wide;
}
.ts-dropdown .option.active {
    @apply bg-accent/10 text-accent;
}
.ts-wrapper.disabled .ts-control {
    @apply bg-gray-100 text-gray-500;
}
/* The original native <select> gets `display: none` once TomSelect mounts — keep it that way to avoid a flash */
select[data-controller~="tom-select"] { @apply opacity-0; }
.ts-wrapper { @apply opacity-100; }
```

(If `@apply` with arbitrary values doesn't compile in our Tailwind setup, fall back to plain CSS — the dev should check the existing pattern in `assets/styles/`.)

Import this file in `assets/app.js` directly after the TomSelect CSS import:

```js
import 'tom-select/dist/css/tom-select.min.css';
import './styles/tom-select-overrides.css';
```

### 10. Tests

Add **one** integration test asserting the form renders an `<optgroup>`-shaped `<select>` for the largest target (admin onboarding):

`tests/Integration/Controller/Admin/AdminCreateOnboardingControllerTest.php` (or extend the existing one):
- Log in as admin via fixtures.
- `GET /portal/admin/onboarding/digital`.
- Assert response contains `<select` with `name="admin_create_onboarding_form[storageId]"` and `data-controller="tom-select"`.
- Assert the rendered HTML contains at least one `<optgroup label=` whose label matches one of the fixture place names + storage type (e.g. `Brno-Slatina — Box S`).

No JS unit test needed — manually verify TomSelect mounts on a real page (acceptance section).

### 11. Quality gate

Run `docker compose exec web composer quality` — expect green. PHPStan level 8 should be happy with the new `StorageChoiceBuilder` (it has explicit array shape annotations; pay attention to `array<string, array<string, string>>` matching Symfony's expected `'choices'` shape — Symfony's stub may generalise it but the form will accept it).

## Acceptance

- `docker compose exec web composer quality` is green.
- `bin/console importmap:install` has populated `assets/vendor/tom-select/` and the dev server (`docker compose exec web …` to start, or whatever the project uses) loads the page without 404s on tom-select assets.
- `/portal/admin/onboarding/digital` (admin login) shows the storage select with optgroups: each `<optgroup label="{Place} — {Storage type}">` contains storages naturally sorted (`A1, A2, A10`, not `A1, A10, A2`). Typing "br" in the dropdown narrows to Brno places. Typing "boz" matches "Box S" in any place. Typing "misto" with no diacritics matches "místo".
- `/portal/admin/onboarding/migrate` shows the same.
- `/portal/unavailabilities/create` (admin login) shows the global storage select grouped & searchable; (landlord login) shows only the landlord's storages, grouped by place — type, searchable.
- `/portal/storages/{id}/edit` and the create-storage form show place + storage-type selects with TomSelect; storage-type select is grouped by place.
- `/portal/admin/audit-log` and `/portal/admin/email-log` filters render TomSelect dropdowns for the existing entity_type / event_type / template / status filters. Submitting the form still produces the same query string (no JS regression).
- `/portal/calendar` and `/portal/storages` (landlord) — TomSelect on the place / storage_type filters; selecting an option still auto-submits the form (handled by controller, no `onchange` attribute remains).
- Submitting any of the affected forms posts the **same** field name and value as before TomSelect (manually verify in browser devtools — TomSelect leaves the underlying `<select name="…" value="…">` intact).
- A native `<select>` without `data-controller="tom-select"` (e.g. registration form, order form's payment-frequency if any) is **not** affected.
- `composer quality` includes a passing integration test verifying the `data-controller="tom-select"` attribute is on the storage select and the response HTML contains at least one `<optgroup`.

## Out of scope

- **Consumer-facing forms** (registration, login, public order/checkout) keep native `<select>` everywhere. Those forms have at most 2-3 short selects, and TomSelect's mobile-keyboard model is a regression there. (Can be revisited in a separate spec if a real complaint surfaces.)
- **Pretty-printing audit-log entity_type / event_type labels** (e.g. show "Objednávka — Vytvořeno" instead of `App\Entity\Order` / `order.created`). Touches the model and translation layer; orthogonal to widget UX.
- **Storage canvas type drawer** (`templates/portal/storage/canvas.html.twig:239`). The canvas controller writes/reads `.value` directly (`assets/controllers/storage_canvas_controller.js`); wrapping in TomSelect would mean teaching that controller to use `tomSelectInstance.setValue()` / `getValue()`. Single-place scope keeps the option list short anyway. Revisit if user asks.
- **Tagging / multi-select widgets**. None of the current selects are multi-select; TomSelect supports it but we don't need it.
- **Server-side typeahead (AJAX-loaded options)**. Even the largest list (admin onboarding storages) is bounded by total available storages on the platform — currently <1000. In-memory grouping is fine. Revisit only if response size becomes a problem.
- **`PlaceFormType.type`, `OrderFormType.rentalType`, `AdminUserFormType.role`, `AdminCreateOnboardingFormType.rentalType` / `paymentMethod` / `monthlyPriceMode`, `UserRoleFormType.role`** — all of these are `EnumType` rendered as `expanded: true` (radio buttons), not `<select>`. Untouched.
- **Replacing the per-place dashboard's RevenueChart `<select>`** (`templates/components/RevenueChart.html.twig:4`). It's a Live Component-bound model (`data-model="months"`); TomSelect would need extra wiring to dispatch the Live `change` event. Three short options, low value. Skip.

## Open questions

1. Should the `RevenueChart` 6/12/24-month selector be included anyway (Live Component caveat)? **Recommendation: no — out of scope above.** If the user wants it, file a follow-up spec.
2. Should the audit-log `event_type` and `entity_type` be relabelled at the same time? **Recommendation: no — separate spec, this one is purely the widget rollout.**

Both default to "no". If the user disagrees, mark spec back to draft and amend Requirements 8 / 11.
