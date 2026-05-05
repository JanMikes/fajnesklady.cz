# 015 — Order accept / signing page redesign (wider layout, inline signing, single consent)

**Status:** done
**Type:** UX
**Scope:** medium (~4 files: 2 templates rewritten — `order_accept.html.twig` + `customer_signing.html.twig`; 1 Stimulus controller refactored — `signature_controller.js`; 1 small change to controller validation? **No** — server stays the same)
**Depends on:** 012 (photo_gallery partial). 013 (price-label rule) is independent but wording in this spec follows it where overlap exists.

## Problem

The contract acceptance step on `/objednavka/{place}/{type}/{storage}/prijmout` and the equivalent token-based signing page `/podpis/{token}` have three friction points that this spec fixes together:

1. **Layout is too narrow and photos take too much room.** `max-w-2xl` (576 px) for both surfaces, with two **full-width photo cards** stacked above the order summary. On a desktop the page reads like a phone screen with oversized photos at the top — the legal recap text and the actual signing controls are pushed below the fold. The photo blocks were added by spec 010/012 as a "last visual confirmation"; that goal still applies, but their **size and position** are wrong here. They belong **inside** the order recap (small, side-by-side with the item details), not stacked above.
2. **Signing is hidden inside a modal.** Today the customer clicks "Podepsat smlouvu" → an Alpine modal opens → tabs switch between "Nakreslit" / "Napsat" → confirm → modal closes → state synced via a `signature:signed` CustomEvent. Three navigation steps to do something that should look like the customer is literally signing on the dotted line. The signing should sit **directly under the contract text**, in the natural page flow, and feel like part of the contract.
3. **Too many checkboxes.** 5–7 separate consent checkboxes (smluvní podmínky, VOP, poučení spotřebitele, provozní řád, GDPR, opakované platby, signature consent, early-start waiver). Each is independently legally meaningful, but the customer reads them as repetitive friction. Consolidate into **one** checkbox with a single, smaller-text statement that enumerates and links every document — preserving each consent's legal record server-side.

The same three issues apply to `templates/public/customer_signing.html.twig` (the token-based signing page that's emailed to customers when their contract is generated separately). It uses the **same** signature controller, the **same** modal pattern, and a **subset** of the checkboxes — fix it in the same pass for consistency.

## Goal

A wider, single-column page that scrolls naturally:

- **Top:** compact recap (item, dates, price) with a **small** photo gallery beside the item details on desktop, stacked on mobile.
- **Middle:** customer info; (when recurring) GoPay parameters block.
- **Long contract text** flows inline, no inner-scroll element.
- **Directly below the contract:** signing date + place; signing method as **two radio choices** with **both panels visible at once**; single "Potvrdit podpis" button captures from the active method.
- **One consent checkbox** with smaller-font text that lists and links every individual term/condition (each remaining clickable to its existing modal/document). Server-side validation is unchanged — every existing `accept_*` field submits `1` when the master checkbox is on.
- **Sticky bottom action:** price + payment logos + submit.

Same shape for both `order_accept.html.twig` (full pre-payment recap, more consents) and `customer_signing.html.twig` (token signing, fewer consents).

## Context (current state)

### Surfaces

- `templates/public/order_accept.html.twig` (737 lines). Container: `max-w-2xl mx-auto` (line 62). Photos block: lines 26-60 (two stacked cards above the recap, hero `max-h-56`). Modal-based signing: `modalOpen` Alpine flag + canvas modal at lines 360-471. Contract text inside `max-h-96 overflow-y-auto` (line 228). Checkbox stack: lines 482-587 (8 checkboxes, conditional ones gated by `place.operatingRulesPath` / `requiresEarlyStartWaiver` / `isRecurring`).
- `templates/public/customer_signing.html.twig` (228 lines). Container: `max-w-2xl mx-auto` (line 15). Modal-based signing (Alpine `modalOpen`). Three checkboxes (`accept_contract`, `accept_vop`, `accept_gdpr`) + `signature_consent`.
- `assets/controllers/signature_controller.js` (183 lines). Already exposes targets for both `drawCanvas` and `typedCanvas`; has `switchToDraw()` / `switchToTyped()` that toggle `hidden` classes via tabs. Used by both surfaces.
- `src/Controller/Public/OrderAcceptController.php:111-122` reads each `accept_*` field separately:
  - `accept_contract`, `accept_vop`, `accept_consumer_notice`, `accept_gdpr`, `signature_consent` (always required)
  - `accept_operating_rules` (when `place.operatingRulesPath`)
  - `accept_recurring_payments` (when `isRecurring`)
  - `accept_early_start_waiver` (when `requiresEarlyStartWaiver`)
  - Plus `signature_data`, `signing_method`, `typed_name`, `style_id`, `signing_place`.
- `src/Controller/Public/CustomerSigningController.php:72-78` reads `accept_contract`, `accept_vop`, `accept_gdpr`, `signature_consent`, `signing_place`, `signature_data`, `signing_method` (and on line 221: `typed_name`, `style_id`).

### Photo gallery partial (spec 012)

- `templates/partials/photo_gallery.html.twig` is the single source of truth for "render photos with lightbox + no crop". It accepts `hero_max_h` / `thumb_max_h` / `show_thumb_strip` overrides — perfect for the smaller form factor we need here.

### Czech wording (with diacritics)

- "Podepsat smlouvu" — final submit (unchanged).
- "Potvrdit podpis" — capture-active-method button beneath the inline signature panels.
- "Způsob podpisu" — heading above the radio group.
- "Nakreslit podpis" / "Napsat podpis" — radio labels.
- "Souhlasím se všemi níže uvedenými podmínkami a dokumenty" — master checkbox lead-in (single sentence).

## Architecture

```
   max-w-4xl mx-auto                                    (was max-w-2xl)

   ┌───────────────────────────────────────────────────────────┐
   │  Shrnutí objednávky  (one card)                           │
   │  ┌──────────────────────────────┬──────────────────────┐  │
   │  │ Pobočka, Skladová jednotka,  │ Photo gallery        │  │
   │  │ Rozměry, Období, Cena        │ (small: max-h-32     │  │
   │  │  (text rows, lg:col-span-2)  │  hero, max-h-12      │  │
   │  │                               │  thumbs; partial)    │  │
   │  └──────────────────────────────┴──────────────────────┘  │
   └───────────────────────────────────────────────────────────┘

   Vaše údaje  (unchanged content, same card)
   Parametry opakované platby  (when isRecurring, unchanged)

   Smlouva o nájmu  (NO inner scroll — text flows; whole page scrolls)
       …existing legal sections…

   Datum a místo podpisu  (unchanged inputs)

   ┌─ Způsob podpisu ─────────────────────────────────────────┐
   │  ◯ Nakreslit podpis        ◉ Napsat podpis               │
   │  ┌─ Draw panel (always visible) ─┐                       │
   │  │ <canvas drawCanvas>            │ (dimmed when typed)   │
   │  └────────────────────────────────┘                       │
   │  ┌─ Typed panel (always visible) ─┐                       │
   │  │ [4 font-style buttons]         │                       │
   │  │ <canvas typedCanvas>           │ (dimmed when drawn)   │
   │  └────────────────────────────────┘                       │
   │  [Vymazat]                          [Potvrdit podpis]     │
   └───────────────────────────────────────────────────────────┘

   ☐ Souhlasím se všemi níže uvedenými podmínkami a dokumenty:
        smluvními podmínkami | [VOP] | [poučením spotřebitele] |
        [provozním řádem] | [zpracováním osobních údajů] |
        použitím elektronického podpisu |
        (if recurring) [podmínkami opakovaných plateb] |
        (if early-start) započetím plnění před uplynutím 14denní lhůty
        →   tiny inline links open the existing modals;
            single Alpine flag `acceptAll` drives n hidden
            <input type="hidden" name="accept_*" value="1"
                   :disabled="!acceptAll"> for each consent.

   { price + payment logos + back / Objednat a zaplatit }
```

## Requirements

### 1. Container width

In **both** templates, change the top-level wrapper:

```diff
- <div class="max-w-2xl mx-auto" x-data="{...}">
+ <div class="max-w-4xl mx-auto" x-data="{...}">
```

`max-w-4xl` (896 px) gives breathing room without making contract paragraphs uncomfortably long. Keep `mx-auto` so it stays centred; on screens < 896 px Tailwind reduces to viewport width naturally.

### 2. Photos inside the recap card (`order_accept.html.twig` only)

`customer_signing.html.twig` doesn't render photos today — leave that surface as is for photos.

In `order_accept.html.twig`, **delete** the entire `{% if storageType.hasPhotos or storage.hasPhotos %}` block at lines 26-60 (the two full-width cards above the recap).

Change the existing **Order Summary** card (around line 92) into a 2-column grid on `lg:` and stack the photo gallery on the right:

```twig
<div class="bg-gray-50 rounded-lg p-4 mb-6">
    <h2 class="font-semibold text-gray-900 mb-3">Shrnutí objednávky</h2>

    <div class="grid lg:grid-cols-3 gap-4">
        {# Item details — unchanged content, just narrower column #}
        <div class="space-y-2 text-sm lg:col-span-2">
            <div class="flex justify-between">
                <span class="text-gray-600">Pobočka</span>
                <span class="font-medium">{{ place.name }}</span>
            </div>
            …all existing rows …
        </div>

        {# Compact photo gallery on the right (desktop), stacked below (mobile) #}
        {% if storageType.hasPhotos or storage.hasPhotos %}
            <div class="space-y-3">
                {% if storage.hasPhotos %}
                    {% include 'partials/photo_gallery.html.twig' with {
                        photos: storage.photos,
                        gallery_key: 'recap-storage-' ~ storage.id,
                        caption: 'Sklad č. ' ~ storage.number,
                        hero_max_h: 'max-h-32',
                        thumb_max_h: 'max-h-12',
                        alt: 'Sklad ' ~ storage.number,
                    } only %}
                {% elseif storageType.hasPhotos %}
                    {% include 'partials/photo_gallery.html.twig' with {
                        photos: storageType.photos,
                        gallery_key: 'recap-type-' ~ storageType.id,
                        caption: 'Typ skladu: ' ~ storageType.name,
                        hero_max_h: 'max-h-32',
                        thumb_max_h: 'max-h-12',
                        alt: storageType.name,
                    } only %}
                {% endif %}
            </div>
        {% endif %}
    </div>
</div>
```

**Decision: when both photo sets exist, prefer unit-specific.** That's the more relevant visual ("this is *your* unit") and fits the smaller surface. Generic type photos remain reachable from the place detail page and the order form sidebar (already handled by spec 012). If we want both in this surface later, a follow-up spec can reintroduce a stacked second mini-gallery — for now, prefer brevity.

### 3. Contract text flows inline (`order_accept.html.twig`)

Around line 228, change:

```diff
- <div class="border border-gray-200 rounded-lg p-4 mb-6 max-h-96 overflow-y-auto bg-white">
+ <div class="border border-gray-200 rounded-lg p-4 mb-6 bg-white">
```

The whole page now scrolls. The contract text becomes part of the document the customer is reading, not a thing nested inside another scroller. Keep the `border` + `rounded-lg` so it visually frames as a "document".

`customer_signing.html.twig` doesn't have an inner-scroll contract block — its order summary is short. No equivalent change needed there.

### 4. Inline signature with both panels visible + radio choice

Replace the **modal** + **separate signature card** pattern with a single inline section directly below the contract text (and in `customer_signing.html.twig`, directly below the customer-info card).

**Markup (drop into both templates, with the surface-specific differences in the surrounding `<form>`):**

```twig
{# Signature — sits directly under the contract text. Both methods visible at
   once; a radio picks which method's canvas is captured by "Potvrdit podpis". #}
<div class="border border-gray-200 rounded-lg p-4 mb-6"
     x-data="{ method: 'draw' }">
    <h3 class="font-semibold text-gray-900 mb-3">Způsob podpisu</h3>

    {# Two radio cards. Big, full-width, easy tap targets. #}
    <div class="grid sm:grid-cols-2 gap-3 mb-4">
        <label class="flex items-center gap-3 p-3 border rounded-lg cursor-pointer transition-colors"
               :class="method === 'draw' ? 'border-accent bg-accent/5' : 'border-gray-200 hover:border-gray-300'">
            <input type="radio" value="draw" x-model="method"
                   class="h-4 w-4 text-accent focus:ring-accent border-gray-300">
            <span class="text-sm font-medium">Nakreslit podpis</span>
        </label>
        <label class="flex items-center gap-3 p-3 border rounded-lg cursor-pointer transition-colors"
               :class="method === 'typed' ? 'border-accent bg-accent/5' : 'border-gray-200 hover:border-gray-300'">
            <input type="radio" value="typed" x-model="method"
                   class="h-4 w-4 text-accent focus:ring-accent border-gray-300">
            <span class="text-sm font-medium">Napsat podpis</span>
        </label>
    </div>

    {# Both panels visible at once. The non-selected one is dimmed (opacity-50)
       and pointer-events disabled so the customer sees what they're not picking
       but can't accidentally interact with it. #}
    <div class="space-y-4">
        <div data-signature-target="drawPanel"
             class="transition-opacity"
             :class="method === 'draw' ? 'opacity-100' : 'opacity-50 pointer-events-none'">
            <p class="text-xs text-gray-500 mb-2">Nakreslete svůj podpis do pole níže:</p>
            <canvas data-signature-target="drawCanvas"
                    class="w-full h-40 border border-gray-300 rounded-lg cursor-crosshair bg-white"></canvas>
        </div>

        <div data-signature-target="typedPanel"
             class="transition-opacity"
             :class="method === 'typed' ? 'opacity-100' : 'opacity-50 pointer-events-none'">
            <p class="text-xs text-gray-500 mb-2">Vyberte styl podpisu:</p>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 mb-3">
                {# 4 style buttons unchanged from current code; data-signature-target / data-action stay #}
                {# (move the existing four <button data-action="click->signature#selectStyle"> blocks here) #}
            </div>
            <canvas data-signature-target="typedCanvas"
                    class="w-full h-32 border border-gray-300 rounded-lg bg-white"></canvas>
        </div>
    </div>

    {# Signed indicator + per-method capture button row. Kept simple — single
       "Potvrdit podpis" pulls from whichever radio is active; "Vymazat" clears
       the active method. #}
    <div class="mt-4 flex items-center justify-between gap-3 flex-wrap">
        <button type="button"
                data-action="click->signature#clear"
                :data-signature-mode-param="method"
                class="btn btn-secondary btn-sm">
            Vymazat
        </button>
        <div class="flex items-center gap-3">
            <div data-signature-target="preview" class="hidden">
                <img data-signature-target="previewImage"
                     class="max-h-12 border border-gray-200 rounded bg-white p-1"
                     alt="Podpis">
            </div>
            <button type="button"
                    data-signature-target="confirmBtn"
                    data-action="click->signature#confirm"
                    :data-signature-mode-param="method"
                    class="btn btn-primary btn-sm">
                Potvrdit podpis
            </button>
        </div>
    </div>

    <div x-show="$root.querySelector('[data-signature-target=\'preview\']').classList.contains('hidden') === false"
         x-cloak
         class="mt-2 flex items-center gap-2">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <span class="text-sm text-green-700">Podpis přidán</span>
    </div>
</div>
```

**Signature controller refactor (`assets/controllers/signature_controller.js`):**

The current controller has `switchToDraw()` / `switchToTyped()` that toggle a `hidden` class on the inactive panel and update tab styles. Both are now obsolete:

- **Delete** the `drawTab` / `typedTab` targets and the two `switchTo*` methods. The radio is plain HTML + Alpine — no controller call needed.
- **Delete** the `open()` method that initialised the canvas after the modal opened. Both canvases are now always in the DOM; initialise both in `connect()`.
- **Add** `connect()` body: call `_initDrawCanvas()` and `_renderTypedSignature()` immediately. They're idempotent today; verify and tighten if needed.
- **Modify** `confirm(event)`: read mode from `event.params.mode` (Stimulus action params, supplied by `data-signature-mode-param` on the button). If `event.params.mode === 'typed'`, capture the typed canvas; otherwise the draw canvas. **Don't** rely on `this.modeValue` any more — Alpine owns the radio state, not Stimulus.
- **Modify** `clear(event)`: same — read mode from `event.params.mode`. If draw, `signaturePad.clear()`. If typed, no-op (typed canvas is regenerated whenever a style is picked).
- **Drop** `selectStyle()` selecting active style ring — keep it, but make sure picking a style while `method === 'draw'` doesn't switch the radio. The Alpine `method` and Stimulus's mode-param at confirm time are independent; that's fine.
- **Keep** `_initDrawCanvas()` / `_renderTypedSignature()` mostly as-is. Both ran via `requestAnimationFrame` after modal open in the current code; now run on `connect()`. Watch for layout-zero-width on first paint — the existing code guards with `rect.width > 0`. Tested working on all current targets per the existing flow.
- **Keep** the `signature:signed` CustomEvent dispatch in `confirm()`. Both templates listen for it via `@signature:signed.window="signed = true"` to enable the submit button.

**Modal removal:**

In **both** templates, **delete**:

- The Alpine state keys `modalOpen`, `vopModalOpen`, `consumerNoticeModalOpen`, `recurringPaymentsModalOpen`, `gdprModalOpen` (the **last four stay** — see Section 5 "Single consent"; the consolidated checkbox still links into them). Only `modalOpen` goes away.
- The signature modal `<div x-show="modalOpen" …>` block (lines 360-471 in `order_accept`, 169-226 in `customer_signing`).
- The "Podepsat smlouvu" trigger button + the empty-state placeholder (lines 318-358 in `order_accept`, 126-150 in `customer_signing`).
- The `@click="modalOpen = true"` and `data-action="click->signature#open"` everywhere.

Hidden form fields (`signature_data`, `signing_method`, `typed_name`, `style_id`) stay — the controller still reads them.

### 5. Single consolidated consent checkbox

Replace the entire `<div class="space-y-4 mb-6">` checkbox block:

- In `order_accept.html.twig`: lines 482-588.
- In `customer_signing.html.twig`: lines 99-115 (4 checkboxes).

with **one** master checkbox + **N hidden mirrors** (one per existing `accept_*` field) that submit `1` when the master is on. The wording is one sentence + a small-font enumeration with each individually-required term linked to its existing modal/document.

```twig
{# Single consolidated consent. Each individual accept_* field is mirrored below
   as a hidden input that's only enabled when the master checkbox is on, so the
   server-side validation in OrderAcceptController / CustomerSigningController
   doesn't need to change. The user reads one sentence + a small-font list of
   what they're agreeing to; each term link still opens the existing modal so
   they can review the actual document. #}
<div class="border border-gray-200 rounded-lg p-4 mb-6">
    <label class="flex items-start gap-3 cursor-pointer">
        <input type="checkbox" x-model="acceptAll"
               class="mt-1 h-5 w-5 text-accent border-gray-300 rounded focus:ring-accent shrink-0">
        <span class="text-sm text-gray-800">
            <strong>Souhlasím se všemi níže uvedenými podmínkami a dokumenty</strong>
            a potvrzuji, že jsem si je přečetl/a:
        </span>
    </label>

    <ul class="mt-2 ml-8 text-xs text-gray-600 list-disc space-y-1">
        <li>smluvními podmínkami uvedenými výše v této smlouvě;</li>
        <li><button type="button" @click.prevent="vopModalOpen = true" class="text-accent hover:underline">všeobecnými obchodními podmínkami</button>;</li>
        {# Operating rules — only when the place has one #}
        {% if place.operatingRulesPath ?? false %}
            <li><a href="{{ upload_url(place.operatingRulesPath) }}" target="_blank" class="text-accent hover:underline">provozním řádem pobočky</a>;</li>
        {% endif %}
        {# Consumer notice — only on order_accept; customer_signing omits #}
        {% if consumerNoticeAvailable ?? true %}
            <li><button type="button" @click.prevent="consumerNoticeModalOpen = true" class="text-accent hover:underline">poučením o právech spotřebitele</button>;</li>
        {% endif %}
        <li><button type="button" @click.prevent="gdprModalOpen = true" class="text-accent hover:underline">zpracováním osobních údajů</button> pro obchodní účely;</li>
        <li>použitím elektronického podpisu jako platného a závazného právního úkonu;</li>
        {% if isRecurring ?? false %}
            <li>založením a parametry <button type="button" @click.prevent="recurringPaymentsModalOpen = true" class="text-accent hover:underline">opakované platby</button> a uložením platebních údajů na bráně GoPay;</li>
        {% endif %}
        {% if requiresEarlyStartWaiver ?? false %}
            <li>výslovným započetím plnění před uplynutím 14denní lhůty pro odstoupení od smlouvy a beru na vědomí, že tím ztrácím právo odstoupit od smlouvy.</li>
        {% endif %}
    </ul>

    {# Hidden mirrors — submit "1" for each consent the controller validates,
       only when the master checkbox is checked. :disabled on a hidden input
       suppresses it from the form submission, which is what we want when off. #}
    <input type="hidden" name="accept_contract" value="1" :disabled="!acceptAll">
    <input type="hidden" name="accept_vop" value="1" :disabled="!acceptAll">
    <input type="hidden" name="accept_gdpr" value="1" :disabled="!acceptAll">
    <input type="hidden" name="signature_consent" value="1" :disabled="!acceptAll">
    {% if consumerNoticeAvailable ?? true %}
        <input type="hidden" name="accept_consumer_notice" value="1" :disabled="!acceptAll">
    {% endif %}
    {% if place.operatingRulesPath ?? false %}
        <input type="hidden" name="accept_operating_rules" value="1" :disabled="!acceptAll">
    {% endif %}
    {% if isRecurring ?? false %}
        <input type="hidden" name="accept_recurring_payments" value="1" :disabled="!acceptAll">
    {% endif %}
    {% if requiresEarlyStartWaiver ?? false %}
        <input type="hidden" name="accept_early_start_waiver" value="1" :disabled="!acceptAll">
    {% endif %}
</div>
```

**Alpine state changes (in both templates):**

In the `<div x-data="{...}">` declaration, replace:

- `order_accept.html.twig` lines 63-79: drop `acceptContract`, `acceptOperatingRules`, `signatureConsent`, `acceptVop`, `acceptConsumerNotice`, `acceptGdpr`, `acceptRecurringPayments`, `acceptEarlyStartWaiver` — replace with `acceptAll: false`. Drop `modalOpen`. Keep `signed`, `signingPlace`, `vopModalOpen`, `consumerNoticeModalOpen`, `recurringPaymentsModalOpen`, `gdprModalOpen` (still used by the term-link modals).
- `customer_signing.html.twig` lines 16-24: drop `acceptContract`, `acceptVop`, `acceptGdpr`, `signatureConsent` — replace with `acceptAll: false`. Drop `modalOpen`. Keep `signed`, `signingPlace`, plus add `vopModalOpen: false`, `gdprModalOpen: false` (the term links inside the consolidated consent need them; today these don't exist on this surface — the existing 3 checkboxes don't link into modals — so adding the modal divs is part of this spec). Wire up the `_terms_and_conditions_content.html.twig` and `_privacy_policy_content.html.twig` includes the same way `order_accept.html.twig` does at lines 623-705.

**Submit-button gating:**

Both templates currently disable the submit button via a long boolean expression: `:disabled="!(signed && acceptContract && acceptVop && … && signingPlace.trim())"`. Simplify:

```twig
{# order_accept #}
:disabled="!(signed && acceptAll && signingPlace.trim())"

{# customer_signing — same #}
:disabled="!(signed && acceptAll && signingPlace.trim())"
```

### 6. Server-side: NO changes required

`OrderAcceptController.php:111-122` and `CustomerSigningController.php:72-78` keep checking each field. Because the Alpine `:disabled` on hidden mirrors only suppresses fields when the master is **off**, when the form submits with the master **on** every legacy field arrives as `1` — exactly what the controller expects. No PHP change. No migration. The legal record (which fields the customer accepted) remains unchanged.

**Defensive note for the implementer:** verify in dev that submitting with `acceptAll = false` leaves *every* `accept_*` field absent from the request payload. Symfony's `Request::request->getBoolean('accept_X')` returns `false` for absent fields — so the controller's "missing → reject" logic continues to work.

### 7. Wiping consents on form reset (edge case)

Today the page handles "back / forward" navigation by Alpine state being persisted across DOM but reset on full reload. The new design's master checkbox + hidden mirrors continue to work the same way. Verify: after the customer toggles `acceptAll`, hits browser back, then forward — the checkbox should be cleared and submit should be disabled until they re-check. Standard Alpine behaviour; no extra code.

### 8. Tests

- **Functional smoke test (`tests/Integration/Controller/Public/OrderAcceptControllerTest.php`):** if a test exists, extend; otherwise add one: POST to the accept route with all `accept_*` fields = `1`, valid signature data, valid signing place → 302 redirect to payment. POST with `accept_all` interpretation but missing `accept_vop` → 4xx / re-render with error. (The existing controller already enforces this; the test just confirms no regression.)
- **Browser test (manual, not asserted in CI):** load `/objednavka/{place}/{type}/{storage}/prijmout`, confirm: photos appear inline at small size; contract text is no longer in an inner scroller; signing canvases are both visible; toggling the radio dims the inactive one; "Potvrdit podpis" captures from the active method; one master checkbox unblocks the submit button. Repeat for `/podpis/{token}`.
- **No new unit tests** — the only JS logic change is the Stimulus controller's `confirm`/`clear` reading mode from `event.params` instead of `this.modeValue`, plus moving canvas init to `connect()`. Verify in browser; the existing flow has no test coverage today.

## Acceptance

- `docker compose exec web composer quality` is green.
- On `/objednavka/{place}/{type}/{storage}/prijmout`:
  - Container is `max-w-4xl`. Photos appear **inside** the "Shrnutí objednávky" card on the right (desktop), stacked below text on mobile. Hero ≤ 128 px tall, thumbs ≤ 48 px tall.
  - Contract text flows in the page (no inner scroller); page itself scrolls.
  - Signing section has **two radios** ("Nakreslit podpis" / "Napsat podpis") and **both panels** are visible simultaneously. The non-selected panel is dimmed at `opacity-50` and not interactive.
  - "Potvrdit podpis" captures the **active** method — drawing in one and switching radios then confirming captures the typed one (and vice versa). The previously-emitted `signature:signed` event still toggles the `signed` Alpine flag.
  - Signature **modal is gone** from the DOM. No `modalOpen` flag, no Alpine `x-show="modalOpen"` block.
  - The 5–7 individual consent checkboxes are replaced by **one** master checkbox followed by a small-font `<ul>` listing every term, each linked to its existing modal/PDF.
  - Submitting the form with the master on still produces a payment redirect (controller behaviour unchanged). Submitting with the master off keeps the submit button disabled.
- On `/podpis/{token}` (`customer_signing.html.twig`):
  - Same layout / signing / consent treatment. Reduced consent list (no consumer-notice / operating-rules / recurring-payments / early-start lines — those don't apply to this surface). The two new modals (VOP, GDPR) added so the consent links work.
- No JS errors in the console on either surface.
- Lightbox / signature controllers still work after Live Component morphs (none on these surfaces today, but `connect`/`disconnect` are clean).

## Out of scope

- **Changing the legal text or the set of consents required.** The consolidation is presentational; legally the customer still consents to each enumerated item separately (one server-side flag per consent, mirrored from one checkbox).
- **A fully separate "Sign" CTA at the very bottom of the page** (sticky bar). The single "Objednat a zaplatit" / "Podepsat smlouvu" submit button at the end of the form is enough; sticky bars on a contract-acceptance page tend to feel pushy.
- **Replacing the four font-style buttons with a dropdown / select** for the typed signature. Four fonts visible side-by-side is the current pattern; works fine.
- **A signature-pad-on-touch optimisation** beyond what `signature_pad` already provides.
- **Pre-filling the signature** from a previous signed contract (renewal / spec 014). Each contract is signed individually.
- **Splitting `order_accept.html.twig` into smaller partials.** Worth doing for maintainability later, but a structural refactor at the same time as a UX redesign would muddy the diff. Follow-up.
- **Additional photo treatments** (full-screen viewer beyond GLightbox, lazy loading hints). Spec 012 already gives the partial; we just shrink the size here.
- **Auto-grading the signature** (proof-of-life, intent detection). Out of scope.

## Open questions

None — proceed.
