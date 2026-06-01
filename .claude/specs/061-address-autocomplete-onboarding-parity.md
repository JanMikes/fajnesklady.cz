# 061 — Address autocomplete parity (onboarding) + native inputs + city-triggered suggestions

**Status:** done
**Type:** UX
**Scope:** small (3–4 files: 1 JS controller, 1 macro, 1 onboarding template, optional FormType attrs)
**Depends on:** none (builds on spec 008 Live order form + spec 037 soft-warn address override)

## Problem

The **order form** already has working address autocomplete: type a street → Photon (Komoot) dropdown → click fills street + city + PSČ, plus on-blur live validation and a soft-warn override (`templates/components/OrderForm.html.twig:186`, via the shared `_address_override` macro).

The **admin onboarding form** does NOT — it renders the three billing-address fields with raw `form_row()` (`templates/components/AdminOnboardingForm.html.twig:76-80`), so:
- no autocomplete dropdown, no click-to-fill;
- no browser autofill tokens (`autocomplete="street-address"` …);
- the `addressOverride` checkbox is always visible instead of appearing only on a verification failure.

It is already a Live Component and already exposes the `validateField` LiveAction (`src/Twig/Components/AdminOnboardingForm.php:242`), so it can adopt the exact same macro with **zero backend work**.

Separately, the user asked for (a) the same autocomplete to also trigger when typing in the **City** field, not just Street, and (b) mobile-native inputs (numeric keypad on PSČ) across every address form.

## Goal

Both the order form and the admin onboarding form offer identical, robust address entry: the user types into Street (or City), sees a dropdown of real Czech addresses, clicks one, and **all three fields fill**. On mobile the PSČ field shows a numeric keypad. If Photon is unreachable, every field stays a plain editable text input — the user types all three manually and nothing breaks. The override checkbox stays hidden until the server cannot verify the address.

## Context (current state)

### The data model — READ THIS, it drives the whole design

There is **exactly one** address, stored as three separate columns on `User`:
- `src/Entity/User.php:48-54` → `billingStreet`, `billingCity`, `billingPostalCode` (all `?string`).

There is **no** second "company / registered-seat" address. The onboarding/order forms relabel the heading ("Sídlo společnosti" when invoicing a company, otherwise "Fakturační adresa" / "Adresa bydliště") but write to the **same three columns**. Both autocomplete (Photon) and ARES lookup populate those same fields:
- `assets/controllers/ares_lookup_controller.js:3` → `FIELD_NAMES = ['companyName', 'companyVatId', 'billingStreet', 'billingCity', 'billingPostalCode']`.

**Design consequence (this is the answer to the user's "are we doomed if autocomplete fails / are there two addresses" concern):**
- We keep **three real, always-editable text inputs** as the source of truth. Autocomplete is *progressive enhancement* layered on top — never the only way to enter an address. Photon down / empty results ⇒ the user just types into the three fields. No hard dependency.
- We do **not** collapse to a single bound input. "Single-field feel" is achieved by layout only: Street is full-width and primary (where you type + get suggestions); City + PSČ sit in a secondary 2-col row that usually auto-fills. This is the order form's existing layout.
- We do **not** add a second address. One address, two labels. (If a distinct registered-seat address is ever wanted, that's a new column + migration — explicitly out of scope here.)

### Existing pieces to reuse (all solid, confirmed against a live Photon call)

- Shared macro: `templates/components/_address_override.html.twig` — `render(form)` (plain) and `render_live(form)` (adds `blur->live#action validateField` wiring). Renders `billingStreet` full-width, then `billingCity` + `billingPostalCode` in a 2-col grid, then the hidden soft-warn override container. Already sets `autocomplete` tokens (`street-address` / `address-level2` / `postal-code`) and `data-address-autocomplete-target` on each field.
- Stimulus controller: `assets/controllers/address_autocomplete_controller.js` — debounced fetch to `/api/address/suggest`, dropdown render, keyboard nav, click-to-fill via `applySuggestion()` (fills all three + clears override). **Currently only the Street input triggers fetching** (`onInput` is bound to `streetInputTarget` only); the dropdown is hard-anchored to `this.streetInputTarget.parentElement`.
- Endpoint: `src/Controller/Api/AddressSuggestController.php` (rate-limited, `GET /api/address/suggest?q=`).
- Validator: `src/Service/Address/PhotonAddressValidator.php` (`suggest()` returns `AddressSuggestion[]`; each has `street`, `houseNumber`, `city`, `postalCode`, `displayLabel`). Confirmed live: `?q=...&lang=default` returns `properties.{street,housenumber,city,postcode,countrycode}`; CZ-filtered; entries missing city or postcode are dropped.
- The order form (`OrderForm.html.twig`) and registration/landlord/portal-edit forms (`templates/user/register.html.twig`, `templates/user/landlord_register.html.twig`, `templates/portal/user/edit.html.twig`, `templates/components/BillingInfoForm.html.twig`) already render through this macro — so any change to the macro propagates to all of them.

### Components already wired for live validation

- `src/Twig/Components/AdminOnboardingForm.php:242` `validateField(#[LiveArg] string $field)` — same shape as `src/Twig/Components/OrderForm.php:268`. No new LiveAction needed.

## Requirements

### 1. Bring the admin onboarding form to parity (use the shared macro)

File: `templates/components/AdminOnboardingForm.html.twig`.

- Add the import near the top of the template (alongside other `{% set %}` lines, before the markup):
  ```twig
  {% import 'components/_address_override.html.twig' as addr %}
  ```
- Replace the raw address block (currently lines 74-80):
  ```twig
  <h3 ...>{{ invoiceToCompany ? 'Sídlo společnosti' : 'Fakturační adresa' }}</h3>
  <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
      {{ form_row(form.billingStreet) }}
      {{ form_row(form.billingCity) }}
      {{ form_row(form.billingPostalCode) }}
  </div>
  {{ form_row(form.addressOverride) }}
  ```
  with:
  ```twig
  <h3 ...>{{ invoiceToCompany ? 'Sídlo společnosti' : 'Fakturační adresa' }}</h3>
  {{ addr.render_live(form) }}
  ```
  Keep the existing `<h3>` heading and its dynamic label exactly as-is.
- The enclosing card already has `class="card overflow-visible"` and `data-controller="ares-lookup"` — leave both. `overflow-visible` is required so the dropdown isn't clipped; the nested `address-autocomplete` controller (added by the macro) coexists with `ares-lookup`.
- ARES interaction is already safe: `ares_lookup_controller.js` fills the same three fields via programmatic events (`isTrusted === false`), and `address_autocomplete_controller.js#onAddressEdit` only clears the override on `event.isTrusted` edits — so an ARES fill won't wrongly reset the override.

### 2. City-triggered suggestions + dropdown anchored to the active input

File: `assets/controllers/address_autocomplete_controller.js`.

Today only Street triggers fetching and the dropdown is pinned under Street. Make **both** Street and City trigger, and anchor the dropdown under whichever field is being typed into.

- In `connect()`, attach the trigger listeners (`input`, `keydown`, `blur`, `focus`) to **both** `streetInputTarget` and (if present) `cityInputTarget`, instead of Street only. Set `autocomplete` on each as before. Keep the existing `onAddressEdit` (override-clearing) listeners on all editable inputs — they coexist with the trigger `input` listener on the same field.
- Track the active anchor: in `onInput`/`onFocus`, set `this.activeInput = event.target`. `removeDropdown()` may clear it.
- Build the query from the **combined** address context so City-triggered searches are useful (a bare city name yields poor Photon hits). When the user types, query with street + city joined:
  ```js
  buildQuery() {
      const parts = [];
      if (this.hasStreetInputTarget && this.streetInputTarget.value.trim()) parts.push(this.streetInputTarget.value.trim());
      if (this.hasCityInputTarget && this.cityInputTarget.value.trim()) parts.push(this.cityInputTarget.value.trim());
      return parts.join(', ');
  }
  ```
  `onInput` keeps its own min-length gate on the **typed field's** value (`event.target.value.trim().length < 3 → removeDropdown()`), but the string sent to `fetchSuggestions` is `buildQuery()`.
- `renderDropdown()` must append the `<ul>` to `this.activeInput.parentElement` (fall back to `streetInputTarget.parentElement`) and set that wrapper to `position: relative`, instead of hard-coding Street. Keyboard nav (`onKeydown`) must operate on whichever input is active.
- `applySuggestion()` is unchanged — picking a suggestion always fills all three fields (`street + houseNumber`, `city`, `postalCode`) and clears the override. This is the behavior the user described ("when I choose a street it should fill everything"); it already works in the order form and now applies to onboarding too.
- Keep `data-live-ignore` on the dropdown `<ul>` (so Live morphs don't fight it) and keep dispatching synthetic `input` + `change` from `setInputValue` so the Live model re-syncs.

### 3. Native inputs (mobile keypad + autofill tokens)

The macro is the single render path for **every** address form (after Req 1, onboarding included; registration/landlord/edit already use it). Add the native attributes **in the macro** so all forms inherit them in one place.

File: `templates/components/_address_override.html.twig`, in `render_inner`'s `postalAttr`:
```twig
{% set postalAttr = {
    'data-address-autocomplete-target': 'postalCodeInput',
    'autocomplete': 'postal-code',
    'inputmode': 'numeric',
} %}
```
- Keep PSČ as `type="text"` (Czech PSČ is commonly shown as `"110 00"` with a space; `inputmode="numeric"` gives the numeric keypad without forbidding the space — do **not** use `type="number"`, which strips spaces and adds spinners).
- Street keeps `autocomplete="street-address"`, City keeps `autocomplete="address-level2"` (already present).
- **Verify the merge:** render-time `attr` passed via `form_row(...)` must not clobber the `placeholder` / `maxlength` set in the FormTypes (`OrderFormType` PSČ has `maxlength: 10`; both order + onboarding set placeholders). After the change, confirm placeholders and `maxlength` still render on the inputs. If render-time `attr` replaces rather than deep-merges (Symfony version dependent), fold `placeholder` (`'Hlavní 123'` / `'Praha'` / `'110 00'`) and `maxlength: 10` into the macro's attr maps so nothing is lost. The macro is the single source either way.

### 4. (Only if Req 3's merge clobbers FormType attrs) — no separate FormType edits otherwise

Do **not** scatter `inputmode` across the six FormTypes. Centralize in the macro per Req 3. The FormTypes (`OrderFormType`, `AdminOnboardingFormType`, `RegistrationFormType`, `LandlordRegistrationFormType`, `BillingInfoFormType`, `AdminUserFormType`) keep their `placeholder`/`maxlength` and need no change unless the merge check in Req 3 forces folding those into the macro.

## Architecture

```
User types in Street OR City
        │  (input event, debounced 250 ms, min 3 chars on typed field)
        ▼
buildQuery()  →  "Street, City"   ──fetch──►  GET /api/address/suggest?q=
        │                                              │ PhotonAddressValidator.suggest()
        ▼                                              ▼  (CZ-only, has city+postcode)
dropdown rendered under the ACTIVE input          AddressSuggestion[] {street,houseNumber,city,postalCode,displayLabel}
        │  click / Enter
        ▼
applySuggestion() → fills billingStreet (street+houseNumber), billingCity, billingPostalCode
                  → dispatches input+change (Live re-sync) → clears override
                  → three columns persist independently (robust; Photon is enhancement only)
```

## Acceptance

- [ ] Admin onboarding form (`/portal/admin/...` onboarding page): typing a street shows the Photon dropdown; clicking an entry fills Street, City **and** PSČ; the three fields remain individually editable afterward.
- [ ] Admin onboarding form: the `addressOverride` checkbox is hidden by default and only appears (soft-warn amber box) after the server returns an `AddressExists` violation; checking it and re-submitting passes.
- [ ] Order form: typing in the **City** field also triggers the dropdown, anchored under the City input; picking an entry fills all three.
- [ ] Order form: typing in **Street** still triggers the dropdown, anchored under Street (no regression).
- [ ] PSČ inputs across order, onboarding, registration, landlord-registration and portal profile-edit forms show a **numeric keypad** on mobile (`inputmode="numeric"`) and still accept a space; `maxlength`/placeholder unchanged.
- [ ] Browser autofill tokens (`street-address` / `address-level2` / `postal-code`) are present on all five forms' address inputs.
- [ ] With Photon unreachable (simulate: block the endpoint), all address fields remain plain editable inputs and a manually typed address submits successfully — no hard dependency on autocomplete.
- [ ] ARES lookup on the onboarding company branch still fills the address fields and does **not** spuriously reveal/clear the override.
- [ ] `composer quality` is green. (Template/Stimulus changes — also run `composer test` per the integration-test note in memory if any controller/template test covers these forms.)

## Out of scope

- **Second / company-registered address column.** The data model has one address; this spec does not add a registered-seat field or migration. The heading already relabels the single address. (If ever needed, that's a separate spec.)
- **Collapsing to a single combined address input.** Rejected by design — three editable columns stay as the robust source of truth; "single-field feel" is layout-only.
- **Converting registration / landlord-registration / portal-edit forms to Live Components** for on-blur server validation. They already have working autocomplete via `addr.render()`; live validation isn't worth the conversion. They only receive the Req 3 native-input tweaks (inherited from the macro).
- **Changing the Photon provider, caching, or rate limits.** The existing `PhotonAddressValidator` + `AddressSuggestController` are untouched.
- **PSČ format validation / pattern enforcement.** No new constraint on the postal code beyond existing `AddressExists` soft-warn behavior.

## Open questions

None — proceed.
