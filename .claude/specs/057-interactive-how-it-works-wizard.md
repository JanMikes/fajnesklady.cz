# 057 — Interactive "Jak to funguje?" wizard modal

**Status:** done
**Type:** UX / feature
**Scope:** medium (~4 files, ~350 lines new/changed)
**Depends on:** none

## Problem

The "Jak to funguje?" section (`templates/user/home.html.twig:173-226`) is static decoration. Three step cards explain the process but are not actionable — customers must manually scroll to the map, find a place, click through to the detail page, find a storage type, then start an order. The gap between the explanation and the action creates friction and makes the homepage feel passive.

## Goal

Turn the three step cards into clickable entry points that launch a single multi-step wizard modal guiding the customer from **place selection → storage type selection → order CTA**. Geolocation-aware: when granted, the modal leads with the 3 nearest places sorted by distance. When denied, all places are shown. The entire flow feels like one continuous action matching the 3 steps described on the cards.

## Context (current state)

- **Step cards**: `templates/user/home.html.twig:181-224` — three static `<div>` blocks with icons, numbers, titles, descriptions. Not clickable.
- **`placesData` JSON**: Already serialized by `HomeController` (`:87-101`) and passed to the template. Contains everything needed: place id/name/address/city/lat/lng/url/isAvailable/lowestPrice/lowestAreaM2 + `storageTypes[]` (id/name/dimensions/floorAreaM2/pricePerMonth/isAvailable/orderUrl). **No additional backend data needed.**
- **Geolocation**: `map_controller.js:371-532` implements localStorage consent (`fajnesklady.geolocation.consent`), haversine distance calculation (`distanceKm`), and the grant/deny/auto-locate flow. The wizard will duplicate the haversine formula (~10 lines) and read the same localStorage key — no shared module extraction.
- **Existing modal patterns**: `map_controller.js:310-367` has custom mobile modal + bottom sheet (`.map-modal`, `.bottom-sheet` in `app.css:411-453`). The wizard modal follows the same structural pattern (backdrop + panel) but is a separate, centered overlay for all breakpoints.
- **Map controller data attribute**: `data-map-places-value` lives on `#pobocky` section (`:72-74`). The wizard controller gets its own `data-place-wizard-places-value` on the `#jak-to-funguje` section — independent, no coupling.

## Requirements

### 1. Make step cards clickable (`templates/user/home.html.twig`)

Turn each of the three card `<div>`s into `<button>` elements (semantic + accessible) with Stimulus actions.

```twig
{# Wrap the #jak-to-funguje section with the wizard controller #}
<section id="jak-to-funguje" class="section-gradient-light"
         data-controller="place-wizard"
         data-place-wizard-places-value="{{ placesData|json_encode|e('html_attr') }}">
```

Each card becomes:
```html
<button type="button"
        class="relative text-left w-full group"
        data-action="place-wizard#open">
    <div class="bg-white rounded-2xl p-8 shadow-lg
                group-hover:shadow-xl group-hover:border-primary/30
                transition-all h-full border border-gray-100
                cursor-pointer">
        {# ... existing icon + number badge + title + description ... #}
        <p class="text-primary font-semibold text-sm mt-4 flex items-center
                  group-hover:translate-x-1 transition-transform">
            Začít zde
            <svg class="h-4 w-4 ml-1" ...arrow-right.../>
        </p>
    </div>
</button>
```

Changes to each card:
- Outer `<div class="relative">` → `<button type="button" class="relative text-left w-full group" data-action="place-wizard#open">`
- Add `group-hover:shadow-xl group-hover:border-primary/30 transition-all cursor-pointer` to inner div
- Append a red "Začít zde →" CTA line below the description paragraph (visible on all cards, same text — the wizard always starts at step 1)

### 2. New Stimulus controller (`assets/controllers/place_wizard_controller.js`)

Pure client-side. No Live Component — all data is already available in `placesValue`.

**Values:**
- `places`: Array (the `placesData` JSON)

**Targets:**
- `modal`: The wizard modal wrapper
- `backdrop`: Dark overlay
- `stepIndicator`: Step circles container
- `stepContent`: Content area that swaps per step
- `backButton`: Back nav (hidden on step 1)

**State (instance properties):**
- `currentStep` (1 | 2 | 3)
- `selectedPlace` (place object or null)
- `selectedType` (storage type object or null)
- `userLocation` ({ lat, lng } or null)

**Lifecycle:**

```
connect():
  - Resolve geolocation silently if localStorage consent === 'granted'
    (same pattern as map_controller.js:371-389 — check permissions API first,
     then getCurrentPosition with maximumAge 5min)
  - Store result in this.userLocation (no map marker, no sorting — just cache the coords)

open():
  - Set currentStep = 1, clear selections
  - If !this.userLocation && readGeoConsent() !== 'denied':
      show geolocation prompt inside step 1 (before place list)
  - Render step 1 content
  - Show modal (remove 'hidden', add body overflow-hidden)

close():
  - Hide modal, restore body overflow

back():
  - currentStep-- (min 1), re-render

selectPlace(event):
  - Read place ID from event.currentTarget.dataset.placeId
  - Set this.selectedPlace
  - Advance to step 2, render

selectType(event):
  - Read type ID from event.currentTarget.dataset.typeId
  - Set this.selectedType
  - Advance to step 3, render
```

**Step 1 — Vyberte pobočku:**

```
renderStep1():
  let places = [...this.placesValue]

  // Geolocation prompt (only when consent not yet decided)
  if (!this.userLocation && this.readGeoConsent() !== 'denied'):
    show a compact banner at top:
      "📍 Povolte polohu pro zobrazení nejbližších poboček"
      [Povolit polohu] button  ·  "nebo vyberte ručně níže" muted text

  // Sort by distance if location known
  if (this.userLocation):
    places.forEach(p => p._distance = this.distanceKm(...))
    places.sort((a, b) => a._distance - b._distance)
    places = places.slice(0, 3)  // show top 3 only when geo-sorted
    // + "Zobrazit všechny pobočky" link at bottom → re-renders without slicing

  // Render place cards (compact, clickable)
  Each card: button with data-action="click->place-wizard#selectPlace"
    - Place name (bold)
    - City + distance badge if known ("≈ 2,3 km")
    - Availability: green "K dispozici" or gray "Obsazeno"
    - Lowest price: "od X Kč / měsíc"
    - Lowest area: "od X m²"
```

When geolocation is granted mid-flow (user clicks "Povolit polohu"):
- Request geolocation, on success write consent + store coords + re-render step 1 (now sorted, top 3)
- On deny: write consent 'denied', remove the banner, show all places

**Step 2 — Zvolte velikost:**

```
renderStep2():
  const types = this.selectedPlace.storageTypes
  // Header: selected place name + "Zpět" link

  // Storage type cards (one per type)
  Each card: button with data-action="click->place-wizard#selectType"
    - Type name (bold) + dimensions ("2,0 × 1,5 × 2,0 m")
    - Floor area ("3,0 m²")
    - Price: "od X Kč / měsíc"
    - Availability badge (green/gray)
    - Unavailable types are shown but visually muted + disabled (no click action)
```

**Step 3 — Objednejte online:**

```
renderStep3():
  // Summary card:
  - Place name + city
  - Storage type name + dimensions + price
  - Separator

  // What to expect (brief, 3 bullets):
  - "Vyplníte kontaktní údaje"
  - "Podepíšete smlouvu online"
  - "Zaplatíte kartou nebo převodem"

  // Big CTA button:
  <a href="{selectedType.orderUrl}" class="btn btn-primary btn-lg w-full">
    Přejít k objednávce →
  </a>

  // Secondary link:
  <a href="{selectedPlace.url}">Zobrazit detail pobočky</a>
```

**Step indicator** (top of modal, all 3 steps):

Horizontal row of 3 circles connected by lines. Circle states:
- **Completed**: filled primary color, white checkmark
- **Current**: filled primary color, white number
- **Future**: gray border, gray number

Labels under circles: "Pobočka" / "Velikost" / "Objednávka"

**Keyboard:** Escape closes modal. Rendered via `keydown` listener added on open, removed on close.

**Haversine:** Duplicate the formula from `map_controller.js:508-516` — 10 lines, not worth extracting into a shared module.

**Geo consent helpers:** Same `localStorage` key `fajnesklady.geolocation.consent`, same read/write pattern as `map_controller.js:518-532`.

### 3. Modal markup (`templates/user/home.html.twig`)

Add the modal skeleton inside the `#jak-to-funguje` section (after the grid), as Stimulus targets:

```html
{# Wizard modal #}
<div data-place-wizard-target="modal" class="wizard-modal hidden" role="dialog" aria-modal="true">
    <div data-place-wizard-target="backdrop"
         data-action="click->place-wizard#close"
         class="wizard-modal__backdrop"></div>
    <div class="wizard-modal__panel">
        <div class="wizard-modal__header">
            <button type="button"
                    data-place-wizard-target="backButton"
                    data-action="place-wizard#back"
                    class="wizard-modal__back hidden">
                ← Zpět
            </button>
            <button type="button"
                    data-action="place-wizard#close"
                    class="wizard-modal__close" aria-label="Zavřít">×</button>
        </div>
        <div data-place-wizard-target="stepIndicator" class="wizard-modal__steps"></div>
        <div data-place-wizard-target="stepContent" class="wizard-modal__content"></div>
    </div>
</div>
```

The step indicator and content are rendered by JS (innerHTML). This avoids 3 duplicate template blocks and keeps the wizard self-contained.

### 4. Wizard modal styles (`assets/styles/app.css`)

Add after the existing `.closest-chip` block (~line 459):

```css
/* Place wizard modal */
.wizard-modal {
    @apply fixed inset-0 z-50 flex items-center justify-center p-4;
}

.wizard-modal__backdrop {
    @apply absolute inset-0 bg-black/50;
}

.wizard-modal__panel {
    @apply relative w-full max-w-lg bg-white rounded-2xl shadow-2xl overflow-hidden;
    max-height: 85vh;
    display: flex;
    flex-direction: column;
}

.wizard-modal__header {
    @apply flex items-center justify-between px-6 pt-5 pb-2;
}

.wizard-modal__back {
    @apply text-sm text-gray-500 hover:text-gray-800 transition-colors font-medium;
}

.wizard-modal__close {
    @apply ml-auto text-2xl leading-none text-gray-400 hover:text-gray-700 transition-colors;
}

.wizard-modal__steps {
    @apply flex items-center justify-center gap-0 px-6 py-3;
}

.wizard-modal__content {
    @apply px-6 pb-6 overflow-y-auto;
    flex: 1;
}

/* Step indicator circles */
.wizard-step {
    @apply flex items-center;
}

.wizard-step__circle {
    @apply w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold border-2 transition-colors;
}

.wizard-step__circle--completed {
    @apply bg-primary border-primary text-white;
}

.wizard-step__circle--current {
    @apply bg-primary border-primary text-white;
}

.wizard-step__circle--future {
    @apply bg-white border-gray-300 text-gray-400;
}

.wizard-step__label {
    @apply text-xs mt-1 text-center;
}

.wizard-step__line {
    @apply w-12 h-0.5 mx-1;
}

.wizard-step__line--active {
    @apply bg-primary;
}

.wizard-step__line--inactive {
    @apply bg-gray-300;
}

/* Wizard place/type cards */
.wizard-card {
    @apply w-full text-left p-4 rounded-xl border border-gray-200 hover:border-primary/40 hover:shadow-md transition-all cursor-pointer bg-white;
}

.wizard-card:disabled {
    @apply opacity-50 cursor-not-allowed hover:border-gray-200 hover:shadow-none;
}

.wizard-card + .wizard-card {
    @apply mt-3;
}
```

### 5. No backend changes

`HomeController` already provides all required data via `placesData`. No new routes, commands, queries, or entities.

## Acceptance

- [ ] All three "Jak to funguje?" step cards have `cursor-pointer`, hover lift effect, and "Začít zde →" CTA text
- [ ] Clicking any card opens the wizard modal at step 1
- [ ] Step 1 shows geolocation prompt when consent undecided; clicking "Povolit polohu" triggers browser geo prompt
- [ ] When geolocation granted: top 3 nearest places shown with distance badges, sorted by proximity
- [ ] "Zobrazit všechny pobočky" link below the top-3 list expands to all places
- [ ] When geolocation denied or unavailable: all places listed immediately
- [ ] Clicking a place card advances to step 2 showing that place's storage types
- [ ] Unavailable types are visible but muted and not clickable
- [ ] Clicking an available type advances to step 3 with summary + "Přejít k objednávce →" CTA
- [ ] CTA links to the correct `orderUrl` from `placesData` (`/objednavka/{placeId}/{storageTypeId}`)
- [ ] Step indicator shows correct state (completed/current/future) at each step
- [ ] Back button navigates to previous step; hidden on step 1
- [ ] Escape key and backdrop click close the modal
- [ ] Body scroll locked while modal open
- [ ] Mobile: modal takes full width with `p-4` margin, content scrollable
- [ ] Geolocation consent syncs with the map section (same `localStorage` key — granting in wizard makes the map auto-sort on next load)
- [ ] `composer quality` passes (no backend changes, but verify asset build)

## Out of scope

- **Storage type photos in modal** — the modal is a lightweight funnel, not a full catalog. Photos are on the place detail page (linked from step 3).
- **Live Component / server rendering** — all data is already in the client-side `placesData` JSON. A Live Component would add unnecessary round-trips for no new data. Pure Stimulus is faster and simpler.
- **Shared geolocation module** — duplicating haversine (~10 lines) + localStorage helpers (~15 lines) in the wizard controller is cheaper than extracting a module, re-wiring imports, and testing the extraction. If a third consumer appears, refactor then.
- **Animated step transitions** — keep it snappy. Content swaps instantly via innerHTML. CSS transitions on the modal open/close (opacity + scale) are fine but step-to-step slide animations are polish for later.
- **Price tier display (short-term/long-term/yearly)** — `placesData` carries `pricePerMonth` (long-term monthly). Showing all tiers in a compact wizard card would clutter. Full pricing is on the place detail page.
- **Changes to the map section** — the `#pobocky` section and `map_controller.js` remain untouched. The wizard is additive.

## Open questions

None — proceed.
