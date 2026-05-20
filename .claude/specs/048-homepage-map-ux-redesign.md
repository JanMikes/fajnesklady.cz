# 048 — Homepage map UX redesign: compact list, side popovers, smart-sort by availability, geolocation, mobile bottom sheet

**Status:** ready
**Type:** UX (homepage marketing surface)
**Scope:** medium (~6 files: `HomeController.php`, `home.html.twig`, `map_controller.js`, `app.css`, 1 new `geolocation_consent_controller.js` helper, fixtures/manual walk-through). No new entities, no new commands, no new routes.
**Depends on:** none. Touches public homepage only; portal map (`storage_map_controller.js`) untouched.

## Problem

The homepage map section (`#pobocky`) is the primary discovery surface but it has accumulated UX debt:

1. **Edge-to-edge full-width.** The section sets `{% block main_class %}min-h-screen{% endblock %}` (templates/user/home.html.twig:68) so the place list + map runs side-to-side with no container padding — neighboring sections use `max-w-6xl mx-auto px-4` and the visual rhythm breaks.
2. **Place cards are too tall.** Each card shows lowest price + full address + a row of every storage-type name pill + a CTA (templates/user/home.html.twig:89-118). On a place with 5 types the card is ~180 px tall — fewer fit above the fold and the eye gets lost.
3. **Popovers open above the marker** (`popupAnchor: [0, -(size/2)]` in assets/controllers/map_controller.js:108). They cover the pin and surrounding pins; on a cluster like Brno you can't see siblings while reading.
4. **Popover content overstates detail.** Lists every type with a numeric "✓ N volných" / "✗ Obsazeno" line (assets/controllers/map_controller.js:198-201) — the count is operational noise. Customers care about "can I get a unit here, yes/no".
5. **No spatial cue for the user.** Map opens centered on `[49.5, 17.5]` (CZ centroid). A visitor in Plzeň has to pan/zoom to find the closest place; we never ask for geolocation.
6. **Mobile UX is poor.** Cards stack above the map (`.places-list-col` then `.places-map-col` at 400 px height) — the user must scroll past N cards before seeing the map; tapping a pin then opens a constrained Leaflet popup that barely fits the modal content.

## Goal

A homepage map section that:

- Sits inside the same container rhythm as neighboring sections (no edge-to-edge break).
- Surfaces the most relevant place first by default — using an **internal % availability ratio** (highest ratio wins) — without ever showing the % to the customer. Customer-facing copy stays binary: **K dispozici** vs **Obsazeno**.
- Asks the visitor for geolocation behind a single button (`Najít nejbližší pobočku`), remembers consent in `localStorage`, and on subsequent visits silently re-sorts by distance. When the closest place is outside the current viewport, a small in-map chip surfaces it (`Nejbližší: Brno-Slatina (≈ 47 km)`).
- Replaces the verbose left-column cards with two-line **compact cards**: title + city, "Od XXX Kč/měs · od X,X m²", binary availability badge, ›  arrow.
- On desktop, opens **side popovers** (right of the pin by default, auto-flip to left near the right edge); on mobile (<768 px) opens a **fullscreen modal** with the same content plus a close × in the header.
- On mobile, **hides the place list entirely** and surfaces it via a floating **`Seznam poboček (N)`** pill that opens a bottom sheet of compact cards.

## Context (current state)

### Files in scope

- `src/Controller/HomeController.php:27-79` — builds `$placesData` (the JSON the Stimulus map consumes) and `$placesWithStorageTypes` (the cards). Per-type `availableCount` is computed via `StorageAssignment::countAvailableStorages` (src/Service/StorageAssignment.php:135) over the next 30 days starting tomorrow.
- `templates/user/home.html.twig:72-129` — the `#pobocky` section + cards + map container.
- `assets/controllers/map_controller.js` — Leaflet integration. `connect()` → `initializeMap()` + `addStorageLocations()` + `bindCardHoverEvents()`. Pin → `createIcon()`. Popup HTML → `createPopupContent()`. Card → marker linking via `data-place-id`.
- `assets/styles/app.css:236-320` — `.places-layout`, `.place-card`, `.map-container`, `.storage-popup` rules.

### Existing capability we reuse

- `StorageType::getFloorAreaInSquareMeters()` (src/Entity/StorageType.php:106) — already returns m² for the "od X,X m²" copy. No m² API needed.
- `StorageType::getDefaultPricePerMonthInCzk()` (src/Entity/StorageType.php:96) — already used in `lowestPrice` calc.
- `StorageRepository::findByStorageTypeAndPlace()` (already loaded by `StorageAssignment::countAvailableStorages`) — every call already knows the **total** count per type because it iterates the loaded list. We piggy-back to compute place-level capacity without an extra query.
- `Place::$type` (`PlaceType` enum) — already drives pin `typeColor`; we keep this. No change.

### What's already correct

- The Stimulus controller already wires card → marker hover/click bidirectionally and uses `flyTo` for focus. We keep that whole control surface; we only restyle the popups and re-sort the data.
- `createIcon()` already produces a `divIcon` we can extend. No external icon library.

## Architecture

```
HomeController                         home.html.twig                        map_controller.js
─────────────                          ──────────────                        ──────────────────
  build $placesData (JSON)              section wrapper (container)            connect()
    + totalStorageCount                  ├ desktop list (hidden <lg)            ├ initializeMap()  (Leaflet, dark tiles)
    + availableStorageCount              ├ map container                        ├ renderMarkers()  (binary-availability pin)
    + availabilityRatio (internal)       ├ Najít nejbližší button               ├ bindCardEvents()
    + lowestFloorAreaM2                  ├ Mobile: pill + bottom-sheet          ├ bindGeolocationButton()
    + binary isAvailable                 └ Mobile: fullscreen modal slot        ├ getPersistedGeoConsent()  (localStorage)
                                                                                ├ flyToClosestIfOutsideViewport()
                                                                                └ openPopover()  (desktop side / mobile modal)
                                                                                       ↑
                                                                                       └ same payload either way
```

Sort policy:

```
if geolocation known:
    cards sorted by haversine distance ASC
else:
    cards sorted by availabilityRatio DESC, then by total capacity DESC, then alphabetically
```

Sort happens **server-side** in `HomeController` so the initial paint is correct (no FOUC). When the user grants geolocation, the JS re-orders cards in-place via DOM moves (no AJAX) and re-emits the bottom-sheet contents.

## Requirements

### 1. `HomeController` — enrich `$placesData` and pre-sort

Edit `src/Controller/HomeController.php`. Reuse the already-loaded `StorageRepository::findByStorageTypeAndPlace()` results inside `StorageAssignment` rather than re-querying — small refactor at the controller level.

Add per-place computed fields and sort by ratio. Sketch:

```php
foreach ($places as $place) {
    $storageTypes = $this->storageTypeRepository->findActiveByPlace($place);

    $placeTotal = 0;
    $placeAvailable = 0;
    $lowestPrice = null;
    $lowestAreaM2 = null;
    $typesPayload = [];

    foreach ($storageTypes as $type) {
        $storagesOfType = $this->storageRepository->findByStorageTypeAndPlace($type, $place);
        $totalOfType = \count($storagesOfType);
        $availableOfType = $this->storageAssignment->countAvailableStorages($type, $place, $startDate, $endDate);

        $placeTotal += $totalOfType;
        $placeAvailable += $availableOfType;

        $priceCzk = $type->getDefaultPricePerMonthInCzk();
        $areaM2 = $type->getFloorAreaInSquareMeters();
        $lowestPrice = null === $lowestPrice ? $priceCzk : min($lowestPrice, $priceCzk);
        $lowestAreaM2 = null === $lowestAreaM2 ? $areaM2 : min($lowestAreaM2, $areaM2);

        $typesPayload[] = [
            'id' => $type->id->toRfc4122(),
            'name' => $type->name,
            'dimensions' => $type->getInnerDimensionsInMeters(), // upgrade from getDimensions()
            'floorAreaM2' => round($areaM2, 1),
            'pricePerMonth' => $priceCzk,
            'isAvailable' => $availableOfType > 0,                // binary — no count exposed
            'orderUrl' => $this->urlGenerator->generate('public_order_create', [
                'placeId' => $place->id,
                'storageTypeId' => $type->id,
            ]),
        ];
    }

    $availabilityRatio = $placeTotal > 0 ? $placeAvailable / $placeTotal : 0.0;

    $placesWithStats[] = [
        'place' => $place,
        'storageTypes' => $storageTypes,
        'lowestPrice' => $lowestPrice,
        'lowestAreaM2' => $lowestAreaM2,
        'totalStorageCount' => $placeTotal,
        'availableStorageCount' => $placeAvailable,
        'availabilityRatio' => $availabilityRatio,
        'isAvailable' => $placeAvailable > 0,
    ];

    $placesData[] = [
        'id' => $place->id->toRfc4122(),
        'name' => $place->name,
        'address' => $place->address,
        'city' => $place->city,
        'latitude' => $place->latitude,
        'longitude' => $place->longitude,
        'type' => $place->type->value,
        'typeColor' => $place->type->color(),
        'url' => $this->urlGenerator->generate('public_place_detail', ['id' => $place->id]),
        'isAvailable' => $placeAvailable > 0,
        'lowestPrice' => $lowestPrice,
        'lowestAreaM2' => round((float) $lowestAreaM2, 1),
        // availabilityRatio + counts intentionally NOT included in client payload — they stay server-side.
        'storageTypes' => $typesPayload,
    ];
}

// Sort BOTH arrays by availability ratio DESC (then total capacity DESC, then name ASC).
// The map JSON and the rendered card list MUST stay in the same order.
usort($placesWithStats, function ($a, $b) {
    return [$b['availabilityRatio'], $b['totalStorageCount'], $a['place']->name]
       <=> [$a['availabilityRatio'], $a['totalStorageCount'], $b['place']->name];
});
usort($placesData, /* same predicate, dropping in availabilityRatio from $placesWithStats by id lookup … */);
```

Implementer note: the two sorts must use **the same key**, so compute it once into a `Uuid → ratio` map before either `usort` call. Stay PHPStan-clean.

Drop `availableCount` from the client JSON entirely — the field is no longer used anywhere in the UI.

### 2. Section wrapper — container + responsive layout

Edit `templates/user/home.html.twig:72-129`. Wrap the section's inner content in `<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">` to match the neighboring "Jak to funguje" section. The light gradient overlay (`absolute top-0 …`) stays at full-bleed so the visual fade is preserved.

The desktop layout (≥1024 px) stays list-on-left / map-on-right. On mobile (<1024 px), the list is no longer rendered above the map — see §6.

### 3. Compact place cards (replace existing `place-card` content)

In `templates/user/home.html.twig`, the card body becomes:

```twig
<div class="place-card"
     data-map-target="placeCard"
     data-place-id="{{ item.place.id }}">
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0 flex-1">
            <h3 class="font-bold text-gray-900 text-base leading-tight truncate">{{ item.place.name }}</h3>
            <p class="text-gray-500 text-xs mt-0.5">{{ item.place.city }}</p>
        </div>
        {% if item.isAvailable %}
            <span class="badge-available">K dispozici</span>
        {% else %}
            <span class="badge-sold-out">Obsazeno</span>
        {% endif %}
    </div>
    {% if item.lowestPrice and item.lowestAreaM2 %}
        <p class="text-gray-700 text-sm mt-2">
            Od <strong>{{ item.lowestPrice|number_format(0, ',', ' ') }} Kč</strong>/měs
            · od <strong>{{ item.lowestAreaM2|number_format(1, ',', ' ') }} m²</strong>
        </p>
    {% endif %}
    <a href="{{ path('public_place_detail', {id: item.place.id}) }}"
       class="absolute inset-0" aria-label="Zobrazit detail pobočky {{ item.place.name }}"></a>
</div>
```

Card becomes `position: relative` (CSS) so the absolute link covers the whole card; the `›` arrow becomes a pseudo-element rendered via CSS to avoid HTML noise. **Drop**: the address line, the storage-type pills row, the explicit "Zobrazit detail →" button.

Marker hover/click handlers in `map_controller.js` continue to read `data-place-id` so the bidirectional highlight still works.

### 4. `map_controller.js` — pin styling (binary), side popover, modal

#### 4.1 Pin styling

`createIcon(place, highlighted)` — the icon stops taking a raw color and takes the whole `place` object. When `place.isAvailable === false` the dot is rendered with `opacity: 0.45` and `filter: grayscale(0.8)` so sold-out places visibly fade. Available pins look identical to today. **No numeric badge, no ring, no percentage** anywhere on the pin.

#### 4.2 Side popover (desktop ≥768 px)

Switch `popupAnchor` to horizontal:

```js
// when opening for a marker:
const useRight = this.shouldFlipToLeft(marker) ? -1 : 1;
marker.bindPopup(content, {
    maxWidth: 320,
    minWidth: 280,
    offset: L.point(useRight * (size / 2 + 8), 0), // tip touches the pin's side
    autoPanPadding: L.point(40, 40),
    className: 'side-popup',
});
```

`shouldFlipToLeft(marker)` computes whether the marker's current pixel x is past `(mapWidth - estimatedPopupWidth - 24)` and flips. Re-evaluate on every open (not just once) since pan/zoom moves things.

Popup content (simplified):

```js
createPopupContent(place) {
    const availableTypes = (place.storageTypes ?? []).filter(t => t.isAvailable);
    const badge = place.isAvailable
        ? '<span class="popup-badge popup-badge--available">K dispozici</span>'
        : '<span class="popup-badge popup-badge--sold-out">Aktuálně obsazeno</span>';

    let typesHtml = '';
    if (availableTypes.length > 0) {
        typesHtml = `
            <ul class="popup-types">
                ${availableTypes.map(t => `
                    <li>
                        <div class="popup-types__row">
                            <div class="popup-types__name">
                                ${this.escapeHtml(t.name)}
                                <span class="popup-types__dim">${this.escapeHtml(t.dimensions)}</span>
                            </div>
                            <div class="popup-types__price">${this.formatPrice(t.pricePerMonth)} Kč/měs</div>
                        </div>
                        <a href="${this.escapeHtml(t.orderUrl)}" class="popup-types__cta">Objednat</a>
                    </li>
                `).join('')}
            </ul>`;
    } else {
        typesHtml = `
            <p class="popup-soldout">
                Všechny skladovací jednotky jsou aktuálně obsazené.
            </p>`;
    }

    return `
        <div class="storage-popup">
            <header class="storage-popup__header">
                <h3>${this.escapeHtml(place.name)}</h3>
                <button type="button" class="storage-popup__close" aria-label="Zavřít">×</button>
            </header>
            <p class="storage-popup__address">${place.address ? this.escapeHtml(place.address) + ', ' : ''}${this.escapeHtml(place.city)}</p>
            ${badge}
            ${typesHtml}
            <a href="${this.escapeHtml(place.url)}" class="storage-popup__detail">Zobrazit detail pobočky</a>
        </div>`;
}
```

The close `×` wires up via event delegation in `connect()`:

```js
this.mapContainerTarget.addEventListener('click', (e) => {
    if (e.target.matches('.storage-popup__close')) {
        this.map.closePopup();
        this.closeMobileModal();
    }
});
```

`availableCount`, the `✓ / ✗` glyphs, "N volných" copy, and the "Nedostupné" disabled chip are **removed**. Sold-out place → no type list, just the one-line "Aktuálně obsazené" sentence.

#### 4.3 Mobile fullscreen modal (<768 px)

Add a Stimulus target `modal` and a template element in the markup:

```twig
<div data-map-target="modal" class="map-modal" hidden>
    <div class="map-modal__backdrop" data-action="click->map#closeMobileModal"></div>
    <div class="map-modal__panel" data-map-target="modalBody" role="dialog" aria-modal="true"></div>
</div>
```

In `map_controller.js`, intercept marker click on viewports `<768 px`:

```js
marker.on('click', (e) => {
    if (window.matchMedia('(max-width: 767px)').matches) {
        e.target.closePopup();      // belt + braces — Leaflet would have auto-opened
        this.openMobileModal(place);
    }
    // desktop: native popup binding handles it
});
```

`openMobileModal(place)` injects the SAME `createPopupContent(place)` HTML into `modalBody`, sets `modal.hidden = false`, locks scroll on `<body>`. `closeMobileModal()` unsets `hidden` and restores scroll. ESC key closes too (`document.addEventListener('keydown', …)` in `connect`, removed in `disconnect`).

### 5. `Najít nejbližší pobočku` button + geolocation + persisted consent

#### 5.1 Markup (above the map, inside the new container)

```twig
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-5">
    <p class="text-gray-600 text-sm">Najděte nejbližší pobočku a porovnejte ceny skladovacích jednotek</p>
    <button type="button"
            data-action="map#requestGeolocation"
            data-map-target="geoButton"
            class="btn btn-ghost btn-sm">
        <svg class="h-4 w-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
        </svg>
        Najít nejbližší pobočku
    </button>
</div>
```

The button hides itself (`hidden` attr) once a position is in hand.

#### 5.2 Controller logic

```js
connect() {
    // ... existing init ...
    this.userLocation = null;
    this.maybeAutoLocate();
}

maybeAutoLocate() {
    const consent = localStorage.getItem('fajnesklady.geolocation.consent');
    if (consent !== 'granted' || !navigator.geolocation) return;
    navigator.geolocation.getCurrentPosition(
        pos => this.applyUserLocation(pos.coords.latitude, pos.coords.longitude),
        () => { /* silent; permission revoked browser-side or transient error */ },
        { timeout: 8000, maximumAge: 5 * 60 * 1000 },
    );
}

requestGeolocation() {
    if (!navigator.geolocation) {
        this.showGeoError('Vaše zařízení nepodporuje geolokaci.');
        return;
    }
    this.geoButtonTarget.disabled = true;
    navigator.geolocation.getCurrentPosition(
        pos => {
            localStorage.setItem('fajnesklady.geolocation.consent', 'granted');
            this.applyUserLocation(pos.coords.latitude, pos.coords.longitude);
        },
        err => {
            this.geoButtonTarget.disabled = false;
            if (err.code === err.PERMISSION_DENIED) {
                localStorage.setItem('fajnesklady.geolocation.consent', 'denied');
            }
            this.showGeoError('Polohu se nepodařilo zjistit. Zkuste to znovu nebo si vyberte pobočku ručně.');
        },
        { enableHighAccuracy: false, timeout: 10000 },
    );
}

applyUserLocation(lat, lng) {
    this.userLocation = { lat, lng };
    if (this.hasGeoButtonTarget) this.geoButtonTarget.hidden = true;
    this.addUserMarker();
    this.sortByDistance();              // reorders DOM cards + bottom-sheet cards
    this.flyToClosestIfOutsideViewport();
}
```

`sortByDistance()`:
- Compute haversine distance from `(lat, lng)` to every place with valid coords.
- Re-order `placeCardTarget` DOM nodes ascending. Use `parentNode.appendChild` in distance order — DOM nodes move (no innerHTML rebuild) so Stimulus targets stay live.
- Also re-order the bottom-sheet content list (same node refs — see §6).

`flyToClosestIfOutsideViewport()`:
- Get current `this.map.getBounds()`. If any marker lies inside, no-op.
- Otherwise show a top-right chip on the map (`<div data-map-target="closestChip" class="closest-chip">…</div>`) reading `Nejbližší: {name} (≈ {km} km)`. Click → `flyTo(coords, 13)` + open popup/modal.

`addUserMarker()`: a small blue dot icon at the user's coords, no popup, just a visual cue. Use `L.circleMarker({ radius: 6, color: '#2563eb', fillColor: '#3b82f6', fillOpacity: 0.9, weight: 2 })`.

Haversine helper inline (no library):

```js
distanceKm(a, b) {
    const R = 6371;
    const dLat = (b.lat - a.lat) * Math.PI / 180;
    const dLng = (b.lng - a.lng) * Math.PI / 180;
    const lat1 = a.lat * Math.PI / 180;
    const lat2 = b.lat * Math.PI / 180;
    const h = Math.sin(dLat/2)**2 + Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLng/2)**2;
    return R * 2 * Math.asin(Math.sqrt(h));
}
```

### 6. Mobile bottom-sheet pill + sheet

On viewports `<1024 px` the desktop `.places-list-col` is hidden (`display: none`). Instead, append two elements **inside** the map container:

```twig
{# In template — same parent element as `data-map-target="mapContainer"` #}
<button type="button"
        data-map-target="bottomSheetPill"
        data-action="map#openBottomSheet"
        class="bottom-sheet-pill"
        hidden>
    Seznam poboček ({{ placesWithStorageTypes|length }})
</button>

<div data-map-target="bottomSheet" class="bottom-sheet" hidden>
    <div class="bottom-sheet__backdrop" data-action="click->map#closeBottomSheet"></div>
    <div class="bottom-sheet__panel">
        <div class="bottom-sheet__handle"></div>
        <h2 class="bottom-sheet__title">Pobočky</h2>
        <div class="bottom-sheet__list" data-map-target="bottomSheetList">
            {# Cards re-rendered inside via a small template loop OR via JS clone from desktop list #}
        </div>
    </div>
</div>
```

The pill becomes visible only on viewports `<1024 px` (CSS media query). Controller logic shows the pill on `connect()` after detecting viewport width via `matchMedia('(max-width: 1023px)').matches`.

**Card population**: rather than duplicating Twig markup, on `connect()` JS clones the desktop `placeCard` nodes into `bottomSheetList` (`node.cloneNode(true)`). The clones get a `data-clone="true"` attribute so the controller's hover/click handlers can route either set's `data-place-id` to the same marker. When `sortByDistance()` runs, it reorders BOTH sets identically.

Tapping a card inside the bottom sheet: `closeBottomSheet()` → `focusMarker(placeId)` → on mobile this triggers the fullscreen modal (per §4.3) instead of a popup.

### 7. CSS additions (`assets/styles/app.css`)

Append after the existing `.storage-popup` block:

```css
/* Compact place card */
.place-card {
    @apply relative bg-white rounded-xl px-4 py-3 shadow-sm border border-gray-100 cursor-pointer;
    transition: all 0.2s ease;
}
.place-card::after {
    content: "›";
    @apply absolute top-3 right-3 text-gray-300 text-xl leading-none;
}
.badge-available {
    @apply inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-green-100 text-green-700;
}
.badge-sold-out {
    @apply inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-gray-100 text-gray-500;
}

/* Side popover (Leaflet override) */
.side-popup .leaflet-popup-content-wrapper { @apply rounded-lg shadow-xl; }
.side-popup .leaflet-popup-tip { display: none; }   /* side popover: tip is visually distracting at this offset */
.storage-popup__header { @apply flex items-start justify-between gap-2 mb-1; }
.storage-popup__header h3 { @apply text-base font-bold text-gray-900; }
.storage-popup__close { @apply text-gray-400 hover:text-gray-700 text-xl leading-none; }
.storage-popup__address { @apply text-xs text-gray-500 mb-2; }
.popup-badge--available { @apply inline-block px-2 py-0.5 rounded text-xs font-semibold bg-green-100 text-green-700 mb-3; }
.popup-badge--sold-out { @apply inline-block px-2 py-0.5 rounded text-xs font-semibold bg-gray-100 text-gray-500 mb-3; }
.popup-types { @apply space-y-2 mb-3; }
.popup-types__row { @apply flex items-center justify-between gap-2; }
.popup-types__name { @apply font-medium text-sm; }
.popup-types__dim { @apply block text-xs text-gray-500; }
.popup-types__price { @apply text-sm font-semibold whitespace-nowrap; color: var(--color-accent); }
.popup-types__cta { @apply inline-block mt-1 px-3 py-1 text-xs font-semibold rounded text-white; background-color: var(--color-accent); }
.popup-soldout { @apply text-sm text-gray-600 mb-3; }
.storage-popup__detail { @apply block text-center text-sm font-medium py-2 rounded border; color: var(--color-accent); border-color: var(--color-accent); }

/* Mobile fullscreen modal */
.map-modal { @apply fixed inset-0 z-50 flex items-end sm:hidden; }
.map-modal__backdrop { @apply absolute inset-0 bg-black/50; }
.map-modal__panel { @apply relative w-full h-[85vh] bg-white rounded-t-2xl shadow-2xl p-5 overflow-y-auto; }

/* Bottom-sheet pill + sheet (<1024px) */
@media (max-width: 1023px) {
    .places-list-col { display: none; }
    .places-map-col { height: 70vh; min-height: 480px; }
}
.bottom-sheet-pill {
    @apply absolute bottom-4 left-1/2 -translate-x-1/2 px-5 py-2 bg-white text-gray-800 text-sm font-semibold rounded-full shadow-lg border border-gray-200 z-[400];
}
.bottom-sheet { @apply fixed inset-0 z-50 flex items-end; }
.bottom-sheet__backdrop { @apply absolute inset-0 bg-black/50; }
.bottom-sheet__panel { @apply relative w-full max-h-[75vh] bg-white rounded-t-2xl shadow-2xl p-5 overflow-y-auto; }
.bottom-sheet__handle { @apply w-12 h-1 rounded-full bg-gray-300 mx-auto mb-4; }
.bottom-sheet__title { @apply text-lg font-bold mb-3; }
.bottom-sheet__list { @apply space-y-2; }

/* Closest-place chip */
.closest-chip {
    @apply absolute top-3 right-3 z-[450] bg-white rounded-lg shadow-lg border border-gray-200 px-3 py-2 text-sm cursor-pointer hover:shadow-xl transition;
}
```

**Z-index note**: Leaflet uses 200–700 internally; `.bottom-sheet-pill` uses `z-[400]` so it sits above tiles + markers but below the closest-chip and modal. Test on iOS Safari for stacking quirks.

### 8. Drop / migrate

- Drop the entire current `place-card` rendering (storage-type pills, "Zobrazit detail" button).
- Drop `availableCount` from `placesData` payload.
- Drop the `✓ / ✗` line + "Nedostupné" disabled chip from `createPopupContent`.
- Drop the `popupAnchor: [0, -(size/2)]` line from `createIcon`.
- Drop `popupAnchor` override (`maxWidth: 300, minWidth: 250`) from `bindPopup` — replaced by `offset`.

### 9. Acceptance walk-through (Czech, full diacritics)

After `docker compose exec web composer db:reset`:

1. Open `/` on desktop ≥1280 px. The `#pobocky` section sits inside the same horizontal padding as "Jak to funguje" below it — no edge-to-edge. Cards on the left are compact: two lines + a small green "K dispozici" or gray "Obsazeno" badge. Storage-type pill row + address line are gone.
2. Cards are ordered by highest internal availability ratio first (admin can verify against DB; no UI hint of the number).
3. Hover a card → its pin grows + glows. Click a pin → popover opens **to the right of the pin**, not above. The popover lists only available storage types with `name · dim · price · Objednat`; sold-out types are hidden. If the place is fully sold out the popover shows the "Aktuálně obsazené" sentence with no type list.
4. Click the "Najít nejbližší pobočku" button. Browser asks for permission once. Allow → the button vanishes, a blue dot appears at the user's location, cards re-order by distance, the map flies/fits to include the user and the closest place. Reload the page: the geo lookup runs silently (consent remembered in localStorage), cards land sorted by distance immediately.
5. Pan the map so no marker is visible → the top-right "Nejbližší: … (≈ N km)" chip appears. Click it → fly to that pin + open popover.
6. Resize to 700 px wide (or load on a phone). The left card list is gone. A pill `Seznam poboček (N)` floats above the map. Tap a pin → fullscreen modal with the same content + close × in the header. Tap the pill → bottom sheet slides up with the same compact cards. Tap a card → sheet closes, map flies to pin, fullscreen modal opens.
7. ESC closes either the desktop popover or the mobile modal/sheet. Body scroll is locked while the modal/sheet is open and restored on close.

### 10. Tests

This is overwhelmingly a frontend/UX change. We keep the test surface focused:

- **Unit** `tests/Unit/Controller/HomeControllerSortTest.php` (new, light) — assert the controller sorts both arrays by the same key (ratio DESC, capacity DESC, name ASC) given a fixture with three places of known capacity/availability. Use Symfony's controller test harness with mocked repositories; do not stand up a browser. `MockClock` at `2025-06-15 12:00:00 UTC`.
- **Integration** `tests/Integration/Controller/HomeControllerTest.php` (existing if present; otherwise new) — assert the response no longer contains the old "✓ N volných" copy and DOES contain "K dispozici" badges. Assert the card list is wrapped in the new `max-w-6xl` container.
- **Manual** — the walk-through above. Mobile testing on iOS Safari (real device or BrowserStack) for the bottom-sheet z-index / scroll-lock behavior; document any quirks in the spec's acceptance comment if discovered.

`composer quality` + `composer test` must stay green.

## Out of scope

- **Marker clustering.** With <20 places we don't need leaflet.markercluster; revisit if the network grows past 30 pins.
- **In-bottom-sheet filters or sort UI.** The cards arrive pre-sorted; adding user-toggleable sort/filter is a separate spec.
- **Portal calendar map / order map.** `storage_map_controller.js` is Konva, not Leaflet, and serves a different surface. Untouched.
- **Geolocation API server-side.** No IP-based geolocation fallback; if the browser refuses we silently leave the default ratio-sort. Adding MaxMind/GeoIP2 would push compliance/cost concerns.
- **Pin color encoding availability tier.** Decided against per the user — pin color stays place-type-driven; sold-out only fades opacity.
- **Animated route line from user to nearest place.** Visually noisy; the closest-chip + flyTo cover the wayfinding need.
- **`%` availability copy anywhere user-visible.** Locked-in policy: customers see binary `K dispozici` / `Obsazeno` only. Ratio is internal sort signal.
- **Persistent dismissed-state for the closest-chip.** Each session shows it fresh; not worth a localStorage flag.
- **A/B test framework around the redesign.** Out of scope; we ship the redesign directly.
- **Localizing geolocation error messages.** Czech-only copy is fine — the rest of the site is Czech-only.

## Open questions

None — proceed.
