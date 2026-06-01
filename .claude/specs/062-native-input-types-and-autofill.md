# 062 — Native input types & browser autofill hints (order form full, onboarding suppressed)

**Status:** done
**Type:** UX
**Scope:** small (2 FormTypes + the shared address macro + the onboarding template)
**Depends on:** spec 061 (shares the `_address_override` macro; coordinate the macro signature change below)

## Problem

Inputs should give browsers the right native `type`, `inputmode`, and `autocomplete` signals so mobile shows the correct keyboard, desktop/mobile offer sensible autofill, and validation is native. Two surfaces, two different needs:

- **Order form** (`OrderFormType`, customer-facing): already ~90% correct — `EmailType`/`TelType`/`DateType` with `autocomplete` tokens (`email`, `given-name`, `family-name`, `tel`, `bday`, `new-password`). Gaps: IČO has no numeric keypad hint; company name has no `organization` token.
- **Onboarding form** (`AdminOnboardingFormType`, admin-only): the admin enters the **customer's** details. It has the right Symfony field *types* but **no `autocomplete`/`inputmode` attributes**. Adding real autofill tokens here is the *wrong* move — the browser would offer the **admin's own** saved identity and prompt to save the customer's data under the admin's profile (cross-contamination). The decision (this spec) is to **suppress** browser autofill on the onboarding identity fields while keeping native keyboard hints, and to keep the Photon address dropdown (spec 061) which is a separate feature.

## Goal

- **Order form:** every input carries the best native `type` + `inputmode` + `autocomplete` token so a customer's browser autofills name/email/phone/birth-date/address/company in one tap and the right mobile keyboard appears.
- **Onboarding form:** mobile keyboards still help the admin (`inputmode`/native types), but the browser does **not** inject or offer to save the admin's personal contact data — `autocomplete="off"` on every identity/contact/numeric field. The Photon address autocomplete (geolocation search) keeps working; only the browser's *own* address autofill is suppressed.

## Context (current state)

- `src/Form/OrderFormType.php` — already sets `autocomplete` on email/firstName/lastName/phone/birthDate/plainPassword (lines 38-95). `companyId` (line 100, IČO, `maxlength: 8`) and `companyName` (line 108) have no native hints. `companyVatId` (DIČ, line 115) is plain text. Address fields are owned by **spec 061** (do not touch here).
- `src/Form/AdminOnboardingFormType.php` — `email`/`firstName`/`lastName` (lines 33-47), `phone` (48), `birthDate` (53), `companyName`/`companyId`/`companyVatId` (65-79), `variableSymbol` (189), `customMonthlyPriceInCzk` (167, `NumberType scale 2`), `debtAmountInCzk` (195, `NumberType html5: true` → already `type=number`). None carry `autocomplete` or `inputmode`. Address fields owned by spec 061.
- Shared macro: `templates/components/_address_override.html.twig` — after spec 061 it emits `autocomplete` tokens (`street-address`/`address-level2`/`postal-code`) + `inputmode="numeric"` on PSČ for **every** form that uses it, including (post-061) the onboarding form. This spec needs that token emission to be *suppressible* for the onboarding form only.
- The onboarding form is a Live Component rendered with `novalidate` (`templates/components/AdminOnboardingForm.html.twig:16`); the order form is likewise `novalidate`. So HTML5 `pattern`/`required` do not block submission — `inputmode` is the meaningful native hint, not `pattern`.

### Standards reference (WHATWG autofill detail tokens)

Valid tokens used here: `email`, `given-name`, `family-name`, `tel`, `bday`, `organization`, `new-password`, `street-address`, `address-level2` (city), `postal-code`. There is **no** standard autofill token for a company registration number (IČO) or VAT id (DIČ) — those get `inputmode` only and `autocomplete="off"`. `inputmode` values: `numeric` (digits only), `decimal` (digits + separator), `tel`, `email`.

## Requirements

### 1. Order form — fill the remaining native-hint gaps

File: `src/Form/OrderFormType.php`. Add to the existing `attr` arrays (do not remove placeholders/maxlength):

| Field | Add to `attr` | Rationale |
|---|---|---|
| `companyId` (IČO) | `'inputmode' => 'numeric'`, `'autocomplete' => 'off'` | 8 digits → numeric keypad; no autofill token exists for IČO |
| `companyName` | `'autocomplete' => 'organization'` | standard org-name autofill |
| `companyVatId` (DIČ) | `'autocomplete' => 'off'` | no token; suppress noise (keeps the `CZ…` text keyboard) |

Leave email/name/phone/birthDate/password as-is (already correct). Address fields untouched (spec 061). Date fields (`startDate`/`endDate`) are transactional — no autofill token, `single_text` already yields `type=date`.

### 2. Onboarding form — native keyboards, autofill suppressed

File: `src/Form/AdminOnboardingFormType.php`. Add to each field's `attr` (create the `attr` array where missing):

| Field | Add to `attr` | Note |
|---|---|---|
| `email` | `'autocomplete' => 'off'` | `EmailType` keeps `type=email` (validation + email keyboard) without offering the admin's address |
| `firstName` | `'autocomplete' => 'off'` | |
| `lastName` | `'autocomplete' => 'off'` | |
| `phone` | `'autocomplete' => 'off'` | `TelType` keeps `type=tel` (tel keypad) |
| `birthDate` | `'autocomplete' => 'off'` | `single_text` keeps `type=date` |
| `companyName` | `'autocomplete' => 'off'` | |
| `companyId` (IČO) | `'inputmode' => 'numeric'`, `'autocomplete' => 'off'` | numeric keypad, no autofill |
| `companyVatId` (DIČ) | `'autocomplete' => 'off'` | |
| `variableSymbol` | `'inputmode' => 'numeric'`, `'autocomplete' => 'off'` | "Číselný, max 10 číslic" |
| `customMonthlyPriceInCzk` | `'inputmode' => 'decimal'`, `'autocomplete' => 'off'` | `NumberType scale 2` renders `type=text`; decimal keypad helps |
| `debtAmountInCzk` | `'autocomplete' => 'off'` | already `html5: true` → `type=number`; just suppress autofill (keep existing `min`/`step`) |

Do not change field *types* — `EmailType`/`TelType`/`DateType`/`NumberType` already give the correct native behavior; we only add the attributes. Note in passing: `autocomplete="off"` is a best-effort signal browsers may partially ignore for email/name; that is acceptable ("try to disable" per the decision) and there is no further hardening (no field renaming).

### 3. Make the shared address macro's browser-autofill tokens suppressible

File: `templates/components/_address_override.html.twig` (the spec-061 version).

Add an optional `browserAutofill` parameter (default `true`) to both public macros and thread it into `render_inner`:
```twig
{% macro render(form, browserAutofill = true) %}{{ _self.render_inner(form, false, browserAutofill) }}{% endmacro %}
{% macro render_live(form, browserAutofill = true) %}{{ _self.render_inner(form, true, browserAutofill) }}{% endmacro %}
```
In `render_inner`, when `browserAutofill` is **false**, set `autocomplete` to `'off'` on all three address attrs **but keep**:
- the `data-address-autocomplete-target` attributes (Photon dropdown must still work), and
- `inputmode="numeric"` on the PSČ attr (numeric keypad is still wanted).

So onboarding address fields = Photon dropdown + numeric PSČ keypad, but **no** browser injecting the admin's own street/city/PSČ. Order/registration/portal-edit forms call the macro without the flag → default `true` → unchanged from spec 061.

### 4. Onboarding template passes the suppression flag

File: `templates/components/AdminOnboardingForm.html.twig`. The spec-061 call becomes:
```twig
{{ addr.render_live(form, false) }}
```
All other macro callers (`OrderForm.html.twig`, `register.html.twig`, `landlord_register.html.twig`, `BillingInfoForm.html.twig`, `portal/user/edit.html.twig`) stay as-is (default `true`).

## Acceptance

- [ ] **Order form, mobile:** focusing IČO shows a numeric keypad; company-name field offers the browser's saved organization; email/phone/name/birth-date autofill from the customer's profile in one tap (unchanged).
- [ ] **Order form:** rendered `<input name$="[companyId]">` has `inputmode="numeric"`; `companyName` has `autocomplete="organization"`. Placeholders/maxlength preserved.
- [ ] **Onboarding form:** every identity/contact/numeric input renders `autocomplete="off"`; the browser does **not** prefill the admin's own name/email/phone/address, and does not prompt to save the customer's data under the admin profile.
- [ ] **Onboarding form, mobile:** IČO and variabilní symbol show a numeric keypad; custom price shows a decimal keypad; email/phone/date still use their native keyboards.
- [ ] **Onboarding address fields:** the Photon dropdown still appears and fills all three fields (spec 061 behavior intact); PSČ shows the numeric keypad; `autocomplete="off"` is present on street/city/PSČ.
- [ ] Order/registration/landlord/portal-edit address fields are unchanged (still emit `street-address`/`address-level2`/`postal-code` tokens from spec 061).
- [ ] `composer quality` is green (run `composer test` too if any form/controller test renders these forms).

## Out of scope

- **Address-field autocomplete tokens/inputmode/Photon wiring** — owned by spec 061; this spec only adds the `browserAutofill` *suppression flag* to the macro and passes it from onboarding.
- **Other admin forms** (`AdminUserFormType`, etc.). Same admin-proxy reasoning would apply, but the request covers only the order and onboarding forms.
- **Hardening `autocomplete="off"`** beyond the standard attribute (no honeypot fields, no field renaming). Best-effort suppression is sufficient per the decision.
- **Changing Symfony field types** or adding HTML5 `pattern`/`required` constraints — both forms are `novalidate`; `inputmode` is the meaningful hint and server-side validation remains the source of truth.

## Open questions

None — proceed.
