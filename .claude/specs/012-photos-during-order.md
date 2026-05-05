# 012 — Photos visible across the entire order journey (with lightbox, never cropped)

**Status:** done
**Type:** UX
**Scope:** medium (~7 files: 1 new shared Twig partial; 4 templates updated; 2 controllers extended; 1 Stimulus tweak)
**Depends on:** none. Independent of 010/011.

## Problem

The customer journey from "I'm looking at this place" → "I'm signing the contract" gives almost no visual information about what they're renting. Storage-type photos appear once on the place-detail card as a single cropped thumbnail (`object-cover h-32`) — the rest of the photos are hidden. Storage-specific photos appear in the order form's sidebar but only after a manual map pick — also cropped. The order recapitulation page (`order_accept`) shows zero photos. The storage-map modal already has client-side support for `photoUrls` (`storage_map_controller.js:573-590`) but `OrderCreateController` never sends them, so it falls through to the no-photo branch.

Two things need to change:

1. **Surface coverage.** The customer should see all available photos at every meaningful step of ordering — place detail, map picker, order form sidebar, recapitulation. Both photo sources (`StorageType.photos` = generic, `Storage.photos` = unit-specific) should be visible whenever both exist.
2. **No cropping.** Photos must never be cropped — full image visible, original aspect ratio preserved. Today every render uses Tailwind's `object-cover` which crops. Switch to `object-contain` with a height cap and a neutral background fill (`bg-gray-50`) so portrait/landscape mismatches look intentional rather than awkward.

## Goal

A reusable photo-gallery partial that renders a hero image + thumbnail strip + GLightbox on click, used at every customer-facing point of the order journey. Always shows every available photo (storage-type ∪ storage-specific). Never crops. Captions explain whether each photo is generic ("Typ: …") or unit-specific ("Sklad č. …").

## Context (current state)

**Photo entities** (already in place, no schema change):
- `src/Entity/StorageTypePhoto.php` — `path` (relative to `uploads/`), `position`. Many-to-one to `StorageType`.
- `src/Entity/StoragePhoto.php` — same shape; many-to-one to `Storage`.
- `src/Entity/StorageType.php:217` — `hasPhotos(): bool`, photos ordered by `position ASC`.
- `src/Entity/Storage.php:209` — `hasPhotos(): bool`, photos ordered by `position ASC`.

**Lightbox library** (already installed, already wired):
- `assets/controllers/lightbox_controller.js` — Stimulus controller that initialises GLightbox over `.glightbox` selector inside its element scope. Calls `destroy()` on disconnect.
- `importmap.php:59,62` — `glightbox` JS + CSS entries.
- `assets/vendor/glightbox/...` — vendored locally.
- The controller already handles touch navigation, loop, and ESC/arrow keys. **Don't replace it.**

**Existing photo renders that need fixing** (all currently use `object-cover` and crop):
- `templates/partials/place_detail_content.html.twig:71-74` — single thumbnail per storage-type card on the public + portal browse place detail (one shared partial). Only shows `photos[0]`; never displays the rest.
- `templates/components/OrderForm.html.twig:347-383` — Alpine carousel for storage-type photos in the order form sidebar. Cropped.
- `templates/components/OrderForm.html.twig:385-399` — 3-column grid for storage-specific photos with `glightbox` already attached. Cropped.
- `assets/controllers/storage_map_controller.js:573-590` — modal photo render inside the storage map. Cropped. Falls through to no-render because the data isn't sent.
- `templates/public/order_accept.html.twig` — recapitulation page. No photos at all today.

**Storage-map data flow** (where photos need to be added):
- `src/Controller/Public/OrderCreateController.php:113-125` — builds `storagesData` array. **Does not include photo URLs.** This is what feeds `storage_map_controller.js`.
- `src/Controller/Portal/StorageCanvasController.php:70` — sister controller for the landlord's canvas tool. Same shape; same omission.
- `assets/controllers/storage_map_controller.js:573-590` — `showStorageModal(storage)` already reads `storage.photoUrls` (preferred) or `storage.photoUrl` (legacy fallback). When `photoUrls` is present and length ≥ 1, it renders an inline grid wrapped in `<a class="glightbox" data-gallery="storage-${id}">` so the existing in-component lightbox handler picks it up. **The plumbing is there; the data isn't.**

**`asset()` + uploads convention:** every photo URL is built as `asset('uploads/' ~ photo.path)`. Match this everywhere.

**Czech UI text** (with diacritics):
- "Fotografie typu skladu" — section heading for generic photos.
- "Fotografie skladu č. {číslo}" — section heading for unit-specific photos.
- "Otevřít v plné velikosti" — `aria-label` on the lightbox trigger.

## Architecture

```
            ┌──────────────────────────────────────────────┐
            │    templates/partials/photo_gallery.html.twig │  (new, single source of truth)
            │    inputs: photos, gallery_key, hero_class,  │
            │            thumb_class, caption_prefix       │
            │    output: <a class="glightbox"> hero        │
            │            + thumbnail row                   │
            │            + GLightbox-only hidden anchors   │
            │            so all photos are reachable       │
            │            even when only the hero is shown  │
            │    no-crop: object-contain max-h-* bg-gray-50│
            └──────────────────────────────────────────────┘
                              ▲
        ┌─────────────────────┼──────────────────────┬───────────────────────┐
        │                     │                      │                       │
 place_detail_content    OrderForm.html.twig   order_accept.html.twig   storage_map_controller.js
 (one card per           (sidebar: type      (new: thumbnail strip     (modal: photoUrls now
  storage type)           photos + storage    above shrnutí)            populated by both
                          photos when picked)                            OrderCreateController +
                                                                         StorageCanvasController)
```

## Requirements

### 1. New shared Twig partial: `templates/partials/photo_gallery.html.twig`

Single source of truth for "render a list of photos with lightbox + no crop". Used on all customer-facing surfaces.

**Inputs (all twig variables, all required unless marked optional):**
- `photos` — iterable of `StoragePhoto|StorageTypePhoto`. Each has `.path` and `.position`.
- `gallery_key` — string, used for GLightbox's `data-gallery` so multiple galleries on the same page don't merge (e.g. `storage-type-{id}` or `storage-{id}` or `recap-{orderId}`).
- `caption` — string, optional. Used as `data-glightbox="title: …"` so the lightbox shows e.g. "Sklad č. A12".
- `hero_max_h` — string, optional, default `'max-h-64'`. Tailwind class for hero height cap.
- `thumb_max_h` — string, optional, default `'max-h-20'`. Tailwind class for thumb height cap.
- `show_thumb_strip` — bool, optional, default `true`. When `false`, only the hero is rendered (other photos remain in the lightbox via hidden anchors).
- `alt` — string, optional. `<img alt>`. Default `''`.

**Output structure (sketch):**

```twig
{# templates/partials/photo_gallery.html.twig #}
{% if photos|length > 0 %}
    <div data-controller="lightbox">
        {# Hero: first photo, fully visible, no crop. #}
        <a href="{{ asset('uploads/' ~ photos|first.path) }}"
           class="glightbox block bg-gray-50 rounded-lg overflow-hidden"
           data-gallery="{{ gallery_key }}"
           {% if caption %}data-glightbox="title: {{ caption|e('html_attr') }}"{% endif %}
           aria-label="Otevřít v plné velikosti">
            <img src="{{ asset('uploads/' ~ photos|first.path) }}"
                 alt="{{ alt }}"
                 class="w-full {{ hero_max_h ?? 'max-h-64' }} object-contain mx-auto">
        </a>

        {# Thumbnail strip — when more than 1 photo. Wraps so it never overflows. #}
        {% if show_thumb_strip ?? true and photos|length > 1 %}
            <div class="mt-2 flex flex-wrap gap-2">
                {% for photo in photos|slice(1) %}
                    <a href="{{ asset('uploads/' ~ photo.path) }}"
                       class="glightbox bg-gray-50 rounded overflow-hidden flex-shrink-0"
                       data-gallery="{{ gallery_key }}"
                       {% if caption %}data-glightbox="title: {{ caption|e('html_attr') }}"{% endif %}>
                        <img src="{{ asset('uploads/' ~ photo.path) }}"
                             alt="{{ alt }}"
                             class="{{ thumb_max_h ?? 'max-h-20' }} w-auto object-contain">
                    </a>
                {% endfor %}
            </div>
        {% else %}
            {# When the strip is hidden, keep the remaining photos reachable from the hero
               by emitting hidden lightbox anchors with the same gallery key. #}
            {% for photo in photos|slice(1) %}
                <a href="{{ asset('uploads/' ~ photo.path) }}"
                   class="glightbox hidden"
                   data-gallery="{{ gallery_key }}"
                   {% if caption %}data-glightbox="title: {{ caption|e('html_attr') }}"{% endif %}
                   aria-hidden="true"
                   tabindex="-1"></a>
            {% endfor %}
        {% endif %}
    </div>
{% endif %}
```

**Key decisions baked into the partial:**
- `object-contain` (no crop) + `bg-gray-50` (letterbox fill).
- `data-controller="lightbox"` is on the **wrapper of each gallery**, not the page root. Stimulus instantiates one GLightbox per wrapper, scoped to its `.glightbox` descendants. This avoids cross-gallery contamination on pages that render multiple `photo_gallery` blocks (place detail's storage-type cards, OrderForm's sidebar with type+unit galleries, etc.).
- `data-gallery` keyed per call so the user can navigate within "this set of photos" but doesn't accidentally cycle into a different storage's photos.
- Hidden-anchor fallback so passing `show_thumb_strip: false` still surfaces every photo through the lightbox — important for the place-detail card where space is tight.

### 2. Place detail card — replace single cropped thumbnail with the partial

`templates/partials/place_detail_content.html.twig:69-75` currently:

```twig
{% if storageType.hasPhotos %}
    <div class="mb-3">
        <img src="{{ asset('uploads/' ~ storageType.photos[0].path) }}"
             alt="{{ storageType.name }}"
             class="w-full h-32 object-cover rounded-lg">
    </div>
{% endif %}
```

Replace with:

```twig
{% if storageType.hasPhotos %}
    <div class="mb-3">
        {% include 'partials/photo_gallery.html.twig' with {
            photos: storageType.photos,
            gallery_key: 'storage-type-' ~ storageType.id,
            caption: 'Typ skladu: ' ~ storageType.name,
            hero_max_h: 'max-h-40',
            show_thumb_strip: false,
            alt: storageType.name,
        } only %}
    </div>
{% endif %}
```

`show_thumb_strip: false` keeps the card compact. Customer sees the cover photo at full aspect ratio, clicks → lightbox shows every photo of that type. Critically, the surrounding card's "Objednat" button keeps doing its job — the photo opens the lightbox, not the order form, so the two CTAs don't fight.

### 3. Order form sidebar — drop the Alpine carousel, use the partial twice

In `templates/components/OrderForm.html.twig:346-399`, two galleries today: storage-type photos (Alpine carousel, lines 347-382) and storage-specific photos (lightbox grid, lines 385-399). Replace **both** with `photo_gallery` includes:

```twig
{# Storage-type photos — always shown when present #}
{% if storageType.hasPhotos %}
    <div class="mb-4">
        {% include 'partials/photo_gallery.html.twig' with {
            photos: storageType.photos,
            gallery_key: 'order-type-' ~ storageType.id,
            caption: 'Typ skladu: ' ~ storageType.name,
            hero_max_h: 'max-h-48',
            thumb_max_h: 'max-h-16',
            alt: storageType.name,
        } only %}
    </div>
{% endif %}

{# Storage-specific photos — only when the customer has manually picked a unit #}
{% if not isAutoSelection and selectedStorage.hasPhotos %}
    <div class="mb-4">
        <h3 class="text-sm font-semibold text-gray-700 mb-2">Fotografie skladu č. {{ selectedStorage.number }}</h3>
        {% include 'partials/photo_gallery.html.twig' with {
            photos: selectedStorage.photos,
            gallery_key: 'order-storage-' ~ selectedStorage.id,
            caption: 'Sklad č. ' ~ selectedStorage.number,
            hero_max_h: 'max-h-48',
            thumb_max_h: 'max-h-16',
            alt: 'Sklad ' ~ selectedStorage.number,
        } only %}
    </div>
{% endif %}
```

The Alpine `x-data="{ activePhoto: 0 }"` carousel goes away — the lightbox replaces it. The bulleted-dot pagination + arrows were redundant with what GLightbox already gives the customer in fullscreen.

**Live Component morph note:** `data-controller="lightbox"` lives on each gallery's wrapper (inside the LiveComponent's root). When the LiveComponent re-renders (e.g. on `selectStorage` action), Stimulus's `connect`/`disconnect` lifecycle naturally tears down and re-creates the GLightbox instance on the new node. The existing `lightbox_controller.js` already calls `this.lightbox.destroy()` in `disconnect()` — keep it.

### 4. Recapitulation (`order_accept`) — add a photo strip above "Shrnutí objednávky"

In `templates/public/order_accept.html.twig`, immediately after the breadcrumb's closing `</nav>` and before the `<div class="max-w-2xl mx-auto" x-data="…">` block (line ~25), insert:

```twig
{# Photos — last chance for the customer to visually confirm what they're paying for. #}
{% if storageType.hasPhotos or storage.hasPhotos %}
    <div class="max-w-2xl mx-auto mb-6 space-y-4">
        {% if storageType.hasPhotos %}
            <div class="card">
                <div class="card-body">
                    <h3 class="text-sm font-semibold text-gray-700 mb-2">Fotografie typu skladu — {{ storageType.name }}</h3>
                    {% include 'partials/photo_gallery.html.twig' with {
                        photos: storageType.photos,
                        gallery_key: 'recap-type-' ~ storageType.id,
                        caption: 'Typ skladu: ' ~ storageType.name,
                        hero_max_h: 'max-h-56',
                        thumb_max_h: 'max-h-16',
                        alt: storageType.name,
                    } only %}
                </div>
            </div>
        {% endif %}
        {% if storage.hasPhotos %}
            <div class="card">
                <div class="card-body">
                    <h3 class="text-sm font-semibold text-gray-700 mb-2">Fotografie skladu č. {{ storage.number }}</h3>
                    {% include 'partials/photo_gallery.html.twig' with {
                        photos: storage.photos,
                        gallery_key: 'recap-storage-' ~ storage.id,
                        caption: 'Sklad č. ' ~ storage.number,
                        hero_max_h: 'max-h-56',
                        thumb_max_h: 'max-h-16',
                        alt: 'Sklad ' ~ storage.number,
                    } only %}
                </div>
            </div>
        {% endif %}
    </div>
{% endif %}
```

`storage` and `storageType` are already in template scope on this route (used at lines 70, 74, 219). No controller change needed.

### 5. Wire `photoUrls` into the storage-map data — both controllers

The storage-map modal in `assets/controllers/storage_map_controller.js:573-590` already prefers `storage.photoUrls`; the data just isn't sent today.

In **`src/Controller/Public/OrderCreateController.php:113-125`**, extend the `array_map` callback:

```php
$storagesData = array_map(static fn ($s) => [
    'id' => $s->id->toRfc4122(),
    'number' => $s->number,
    'storageTypeId' => $s->storageType->id->toRfc4122(),
    'storageTypeName' => $s->storageType->name,
    'coordinates' => $s->coordinates,
    'status' => $s->status->value,
    'dimensions' => $s->storageType->getDimensionsInMeters(),
    'pricePerWeek' => $s->getEffectivePricePerWeekInCzk(),
    'pricePerMonth' => $s->getEffectivePricePerMonthInCzk(),
    'isUniform' => $s->storageType->uniformStorages,
    'photoUrls' => array_merge(
        array_map(fn ($p) => '/uploads/' . $p->path, $s->photos->toArray()),
        array_map(fn ($p) => '/uploads/' . $p->path, $s->storageType->photos->toArray()),
    ),
], $storages);
```

The order — unit-specific first, then generic type photos — matches the customer's mental model ("show me this exact unit, then what others of this type look like").

`'/uploads/' . $p->path` mirrors what `asset('uploads/' ~ photo.path)` produces in Twig. The storage-map JS already uses these strings as raw `<img src>` values, so a leading slash + relative path is correct. Don't try to call `UrlGeneratorInterface` for an asset URL — that's the wrong tool. (If asset prefixing ever changes, surface a single helper at that point; today every other photo render in the codebase uses the same string concatenation.)

In **`src/Controller/Portal/StorageCanvasController.php`** at the equivalent `array_map`, apply the **same** addition. Landlords browsing their canvas benefit from the same modal photos.

### 6. No-crop fix in the storage-map modal HTML

`assets/controllers/storage_map_controller.js:576,584` build the modal's `<img>` tags with `object-cover`. Replace with `object-contain` + bg fill + height cap so portrait photos aren't squished. Two minimal edits:

```js
// line 576 — multi-photo grid
`<a href="${url}" class="glightbox" data-gallery="storage-${storage.id}">
    <img src="${url}" alt="Sklad ${storage.number}" class="${index === 0 ? 'w-full max-h-48' : 'w-full max-h-20'} object-contain bg-gray-50 rounded-lg cursor-pointer hover:opacity-80 transition-opacity">
</a>`

// line 584 — single-photo fallback
`<img src="${storage.photoUrl}" alt="Sklad ${storage.number}" class="w-full max-h-48 object-contain bg-gray-50 rounded-lg">`
```

The modal already lives inside `data-controller="storage-map"`, **not** inside `data-controller="lightbox"`. Wrap the modal's `modalPhotosTarget` element (defined in the storage-map's twig template) with `data-controller="lightbox"` so GLightbox initialises over the dynamically-injected `.glightbox` anchors. Inspect `templates/components/storage_map_modal.html.twig` (or wherever the modal markup lives — find via `grep "modalPhotos" templates/`) and add the controller; if the wrapper doesn't yet exist, wrap `modalPhotosTarget` in a `<div data-controller="lightbox">…</div>`. **Verify this manually before declaring done.**

### 7. Confirm no other customer-facing surface is missed

Run before finishing:

```bash
grep -rn "photo\|Photo" templates/public templates/components | grep -iE "object-cover|h-32|h-48"
```

Every hit on a customer-facing template (public/, components/) should be either replaced by the partial or explicitly justified in this spec's "Out of scope".

## Acceptance

- `docker compose exec web composer quality` is green.
- A storage type with 5 photos, viewed on `/pobocka/{id}`:
  - The card shows the first photo, full image visible (no crop), with grey letterbox bars when aspect ratios mismatch.
  - Clicking the photo opens GLightbox; arrow keys + on-screen arrows cycle through all 5 photos.
  - Clicking "Objednat" still navigates to the order form (CTA unaffected).
- On `/objednavka/{placeId}/{storageTypeId}`:
  - Sidebar shows storage-type photos at the top, full image visible, lightbox on click.
  - With `selectionMode = manual` and a unit picked, a second gallery appears below: "Fotografie skladu č. {N}" with the unit's specific photos.
  - Switching to a different unit via the map updates the second gallery (LiveComponent morph + Stimulus reconnect leave no stale GLightbox instances).
- On the storage map (still on the order form):
  - Hovering a non-uniform unit's marker shows the existing tooltip (text only — no change).
  - Clicking it opens the modal; the modal now shows photos (unit-specific first, then storage-type), each `object-contain`, lightbox-enabled.
- On `/objednavka/{placeId}/{storageTypeId}/{storageId}/prijmout` (recapitulation):
  - Above "Shrnutí objednávky" appear up to two cards: type photos + unit photos, each with the partial's hero + thumb strip + lightbox. Both absent if neither is uploaded.
- Every photo render uses `object-contain` (verify with `grep -rn "object-cover" templates/public templates/components` — should yield zero hits in the order journey).
- Lightbox works on mobile (touch swipe) and desktop (arrow keys, ESC closes).
- For a storage type / storage with 0 photos: nothing renders. No empty card, no broken layout.

## Out of scope

- **Handover photos** (`templates/portal/{landlord,user}/handover/view.html.twig`). Different flow, post-rental, separate spec if the customer ever asks. Their `object-cover` stays for now.
- **Admin photo management UIs** (`templates/portal/storage{,_type}/edit.html.twig`). Internal; landlords uploading photos don't need a lightbox to do CRUD.
- **Photo upload UX** — drag-drop, ordering, captions, alt-text editing. Out of scope; users currently upload via existing forms (`AddStoragePhoto`, `AddStorageTypePhoto` commands).
- **Tooltip photos** on the storage map. The hover tooltip stays text-only — adding photos would make it slow and visually noisy. Click-to-open-modal is the right interaction.
- **Image compression / responsive `srcset`.** The PNGs are uploaded as-is and served as-is. Performance optimisation is a separate problem; the no-crop rule and lightbox visibility don't require it.
- **Replacing GLightbox** with a different library. It's already installed, vendored, working, and covers the user's "lightbox or something industry-standard" ask.
- **Photo gallery on the post-payment success page** (`order_complete`). Spec 010 already designs that surface; let it land first, then spec 010 can include the partial if helpful.
- **Map image (`mapa-skladu.png`)** rendered alongside photos. That's a different artefact (place-level layout map, not unit photos) and is handled by specs 010/011.

## Open questions

None — proceed.
