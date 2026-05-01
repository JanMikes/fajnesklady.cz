# 009 — Order form: storage selection mode (auto vs. pick from map)

**Status:** in-progress
**Type:** UX
**Scope:** tiny (~1 file: order_create.html.twig template)
**Depends on:** none. Compatible with spec 008 (Live Component refactor) — see "Compatibility" note.

## Problem

The storage map on `/objednavka/{placeId}/{storageTypeId}/{storageId}` is always rendered, even though most customers don't care which specific unit they get — the controller already auto-assigns the first available one. The map dominates the bottom of the page (Konva canvas, ~400px+ tall), pushes the submit button further down on scroll, and visually invites tinkering for a decision the user usually doesn't need to make. Users who *do* want to pick get the map — but it should be opt-in.

## Goal

Replace the always-visible map with a clear two-option selection at the bottom of the form (same visual pattern as the existing "Typ pronájmu" radio):

- **"Vybrat místo automaticky"** — default. Map stays hidden. The auto-picked storage is what the user gets.
- **"Chci si vybrat místo sám z mapy"** — when picked, the map appears below and the page scrolls to it.

The auto-picked storage (already shown in the right-hand summary sidebar) is still the working selection regardless of which mode the user is in. Switching modes never changes which storage is selected — only whether the map is visible.

## Context (current state)

- `templates/public/order_create.html.twig` — single page template. The form is inside the left column (lines 80–294), the summary is the right column (lines 297–414), the storage map is **always** rendered as its own card below (lines 417–465).
- The map card is a standalone `<div class="card mt-8" data-controller="storage-map" …>` with all `data-storage-map-*-value` attrs. Konva initializes on Stimulus `connect()` and reads `this.containerTarget.clientWidth` (`assets/controllers/storage_map_controller.js:55`) to size the stage. **Gotcha**: if the element is in the DOM but hidden via `display: none` (what Alpine `x-show` produces), `clientWidth` is 0 and the map renders at zero size when later revealed. So we want the element to be **mounted only when needed**, not just hidden — `<template x-if>`, not `x-show`.
- The page already uses Alpine.js heavily (`x-data="orderForm()"` at line 84, `x-show`/`x-cloak` for invoice-toggle and rentalType-toggle blocks). Add this state to the same instance — no new Alpine component needed.
- The "Typ pronájmu" radio at lines 204–224 is the visual reference: a `bg-gray-50 rounded-lg p-6` card with an `<h2>` heading + icon + `{{ form_widget(form.rentalType) }}` (Symfony renders the expanded enum as a styled radio group via the project form theme `templates/form/tailwind_theme.html.twig`). For this spec we render the radios directly (not a Symfony form field — the value is UI-only) but mirror the markup so the look matches.

## Requirements

### 1. Add Alpine state for selection mode

In `templates/public/order_create.html.twig`, extend the existing `orderForm()` Alpine factory (lines 17–75) with one new piece of reactive state:

```js
function orderForm() {
    return {
        // …existing state…
        selectionMode: 'auto',   // 'auto' | 'manual'

        init() {
            // …existing init…
        },

        chooseManual() {
            this.selectionMode = 'manual';
            this.$nextTick(() => {
                const mapEl = document.getElementById('storage-map-card');
                if (mapEl) {
                    mapEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        },
    };
}
```

`chooseManual()` is the only method that needs the scroll side effect; switching back to auto just hides the map, no scroll.

### 2. New radio card in the form

Add a card with the two radio options near the bottom of the form, **just before the "Submit" block** (current line 276, the `<div class="flex items-center justify-between …">`). This places the decision after the user has filled in dates / rental type — the natural moment to ask "do you care which unit?".

Two plain `<input type="radio">` controls bound to `selectionMode`. The "manual" radio also calls `chooseManual()` on change. Mirror the visual pattern from the rentalType card (icon + h2 heading + `bg-gray-50 rounded-lg p-6`, radio rows wrapped in `space-y-3` with the same Tailwind classes the form theme produces for `choice_widget_expanded`):

```twig
<div class="bg-gray-50 rounded-lg p-6">
    <h2 class="text-lg font-semibold text-gray-900 mb-4 flex items-center">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2 text-accent" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l5.447 2.724A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/>
        </svg>
        Výběr skladové jednotky
    </h2>

    <div class="space-y-3">
        <div class="flex items-center">
            <input type="radio" id="selection-mode-auto" name="selection_mode" value="auto"
                   x-model="selectionMode"
                   class="h-4 w-4 text-accent focus:ring-accent border-gray-300">
            <label for="selection-mode-auto" class="ml-3 text-sm text-gray-700 cursor-pointer">
                Vybrat místo automaticky
                <span class="block text-xs text-gray-500">Přidělíme vám první volnou skladovou jednotku tohoto typu.</span>
            </label>
        </div>

        <div class="flex items-center">
            <input type="radio" id="selection-mode-manual" name="selection_mode" value="manual"
                   x-model="selectionMode"
                   x-on:change="chooseManual()"
                   class="h-4 w-4 text-accent focus:ring-accent border-gray-300">
            <label for="selection-mode-manual" class="ml-3 text-sm text-gray-700 cursor-pointer">
                Chci si vybrat místo sám z mapy
                <span class="block text-xs text-gray-500">Po výběru se zobrazí mapa pobočky s rozložením skladů.</span>
            </label>
        </div>
    </div>
</div>
```

The `name="selection_mode"` attribute is cosmetic — no form processor reads it. Adding `name` keeps the two radios mutually exclusive natively (browser behavior); `x-model` binding is what actually drives the Alpine state.

### 3. Replace the always-on map card with a conditional one

Today (lines 417–465) the map card is `{% if place.mapImagePath or storagesJson != '[]' %} <div data-controller="storage-map" …> … </div> {% endif %}`. Wrap that whole block in an Alpine `<template x-if="selectionMode === 'manual'">` so the map element is **only mounted in the DOM** when the user opts into manual mode:

```twig
{% if place.mapImagePath or storagesJson != '[]' %}
    <template x-if="selectionMode === 'manual'">
        <div id="storage-map-card" class="card mt-8"
             data-controller="storage-map"
             data-storage-map-map-image-value="{{ place.mapImagePath ? asset('uploads/' ~ place.mapImagePath) : '' }}"
             data-storage-map-storages-value="{{ storagesJson|e('html_attr') }}"
             data-storage-map-place-id-value="{{ place.id }}"
             data-storage-map-current-storage-type-id-value="{{ storageType.id }}"
             data-storage-map-order-base-url-value="{{ path('public_order_create', {placeId: place.id, storageTypeId: storageType.id}) }}/__STORAGE_ID__"
             {% if highlightStorageId %}data-storage-map-highlight-storage-value="{{ highlightStorageId }}"{% endif %}>
            <!-- existing card-header + container + minimap + tooltip markup, unchanged -->
        </div>
    </template>
{% endif %}
```

**Why `<template x-if>` and not `x-show`**: `x-show` toggles `display: none` while keeping the element mounted; Stimulus `connect()` runs once, `this.containerTarget.clientWidth` evaluates to 0, and the Konva stage is born at 0×0 (broken when revealed). `<template x-if>` removes/inserts the DOM entirely, so Stimulus `connect()` runs the moment the template instantiates (after `chooseManual()`), at which point the element is laid out and `clientWidth` is correct.

The `id="storage-map-card"` is what `chooseManual()` looks up for the smooth scroll. Add it; it doesn't exist today.

The Alpine root (`x-data="orderForm()"` at line 84) currently wraps the form column only — **the map is outside it**. Move the `x-data` block up so it wraps both the form column and the map card, so the `x-if` and `x-model` work. Easiest: put the `x-data` on a parent `<div>` that contains the entire `grid lg:grid-cols-3 gap-8` section AND the map card below. Adjust the Alpine `init()`'s `this.$el.dataset.*` reads — they currently use the inner element's data attributes; either move the `data-*` attrs up to the new outer wrapper, or change the reads to `document.getElementById(...)`. Spec leaves the cleanup choice to the dev; the existing data-attribute set is small (5 entries: weeklyPrice, monthlyPrice, startDateId, endDateId, initialRentalType, initialInvoice, initialStartDate, initialEndDate). Hoisting the wrapper up and moving the data attrs to the new wrapper is the smaller diff.

### 4. Visual default state

On initial render (no Alpine yet boot, JS may be a beat behind), the radio's "auto" must be checked so there's no flash. Add `checked` to the auto radio: Alpine's `x-model` will overwrite it on init, but the static `checked` attribute prevents a momentary "neither" state.

```html
<input type="radio" id="selection-mode-auto" name="selection_mode" value="auto"
       x-model="selectionMode" checked …>
```

The map's `<template x-if>` evaluates falsy on first paint (because Alpine hasn't booted yet), so the map starts hidden naturally — no `x-cloak` needed for it.

### 5. Sidebar copy unchanged

The right-hand summary sidebar already says *"Skladová jednotka bude přiřazena automaticky po vytvoření objednávky."* (lines 401–411). That message is true in **both** modes — the storage was already auto-picked by the controller; manual mode just lets the user override. Leave the copy alone.

## Acceptance

- `docker compose exec web composer quality` is green.
- Open `/objednavka/{placeId}/{storageTypeId}/{storageId}` as a guest:
  - The "Výběr skladové jednotky" radio card is visible at the bottom of the form, above "Rekapitulace".
  - "Vybrat místo automaticky" is selected by default.
  - **No storage map is visible on the page.** Scrolling to the bottom shows the submit row and the order-summary sidebar — that's it.
- Click "Chci si vybrat místo sám z mapy":
  - The map card appears below the form.
  - The page smoothly scrolls down so the map's top edge is visible.
  - The map renders at the correct width (no zero-width Konva stage). All storage rectangles are visible and clickable.
  - Clicking a storage on the map navigates to the new storage URL exactly as before (this spec doesn't change map click behavior).
- Click back to "Vybrat místo automaticky":
  - The map disappears (DOM unmounted).
  - The page does not scroll.
- The "Typ pronájmu" radio still works as before (separate state; no regression).
- Submitting the form with "Vybrat místo automaticky" selected still goes to `/objednavka/.../prijmout` with the storageId from the URL — i.e. the controller's auto-pick.
- Submitting the form with "Chci si vybrat místo sám z mapy" selected behaves identically (the radio doesn't post anywhere; the storageId still comes from the URL).
- No JS console errors on either mode toggle.

## Out of scope

- **Persisting the user's mode choice** across reloads / between visits. If they reload, default = auto. Adding a localStorage adapter is a separate question.
- **Auto-flipping back to "auto" when the user picks a unit on the map.** The two are independent: "manual" means "show the map and let me pick"; once they've picked, the new storageId is in the URL and the map can stay open if they want to change again.
- **A "back to auto-pick" button** that resets to the controller's auto-assigned unit. Not requested; scope creep.
- **Lazy-loading the storages JSON / map image.** The data is already inlined in the page render; only the Konva initialization is deferred (via `x-if` mount). True async loading would be a separate perf spec.
- **Showing the auto-picked unit number prominently in the radio label** (e.g. "Vybrat místo automaticky — sklad 12"). Sidebar already shows it; keeping the radio label generic keeps copy stable across places with different unit numbering.
- **Replacing the existing rentalType radio** with the same custom-rendered pattern. It uses Symfony form widgets for a reason (server-side enum binding); leave it alone.

## Compatibility note (re: spec 008)

If spec 008 (order form as a Live Component) lands first, the relationship stays clean: `selectionMode` is purely Alpine UI state at the page level (outside the Live Component root). The `chooseManual()` scroll target (`#storage-map-card`) is the map card sibling, also outside the Live Component. The `<template x-if>` wrapping pattern works identically inside or outside a Live Component morph. No conflicting requirements.

## Open questions

None — proceed.
