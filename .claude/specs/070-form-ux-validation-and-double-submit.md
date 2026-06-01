# 070 — Form UX overhaul: scroll-to-error, attempt-feedback & bulletproof double-submit on the four critical forms

**Status:** done
**Type:** UX + validation + reliability
**Scope:** large (~14 files: 2 new Stimulus controllers, 4 templates, 2 controllers, 1 Live Component PHP, 1 form-theme tweak, integration tests)
**Depends on:** none (builds on 008/015/050/063/064 which already shipped the Live Components + datepicker plumbing)

## Problem

The four most important forms in the app have inconsistent, partly-broken submit UX:

1. **Order form** (`/objednavka/...`, Live Component) and **Admin onboarding** (`/portal/admin/onboarding`, Live Component) validate server-side on blur/submit and render inline errors — but on a failed submit nothing scrolls; the error can be far above/below the fold and the user thinks the button is dead. Onboarding's submit button is hard-`disabled` until a storage is picked, with no explanation. Neither has any double-submit protection beyond Live's implicit in-flight handling.
2. **Order accept / `OBJEDNÁVÁM a zaplatím`** (`/prijmout`, plain Symfony form + Alpine) and **Customer signing** (`/podpis/{token}`, plain form + Alpine) gate the submit button with Alpine `:disabled` (signature + consents + place). Errors that *do* reach the server render as **top-right flash toasts**, not anchored to fields — no scroll, no inline anchor. Worst of all: **`/prijmout` has zero double-submit protection** — each POST runs `CreateOrderCommand`, so a fast double-click or a retried request creates **duplicate orders**. Customer signing re-processes a sign on a second POST.

These are the revenue- and legally-critical surfaces, full of conditionally-shown fields and dynamic rules, so a "looks dead / silently failed / charged twice" bug here is the worst kind.

## Goal

Across all four forms, a single consistent submit experience:

- **Never silently disable.** The submit button is always clickable. When required/invalid fields remain, clicking it gives immediate visible feedback ("Některá pole nejsou vyplněná — doplňte zvýrazněná pole.") and **smooth-scrolls to the first offending visible field/widget** (and focuses it), accounting for the sticky navbar offset. Conditionally-hidden fields are never targeted.
- **Lock after a valid submit.** Once a submit is genuinely accepted, the button and the form lock (disabled + inline spinner + "Odesílám…"), so a second click does nothing. Defense at **both** layers: the form element guards re-entry and the button is disabled.
- **Server-side idempotency where duplicates are destructive.** `/prijmout` gets a one-time submit token so even a duplicate request that beats the JS (or a JS failure) cannot create a second order — it redirects to the already-created order. Customer signing becomes idempotent (already-signed → redirect, no re-process).

## Context (current state)

### Live Component forms (server-side validation, `novalidate`)
- `src/Twig/Components/OrderForm.php` — `ComponentWithFormTrait`; `submit()` (lines ~282-309) calls `submitForm()` then redirects to `public_order_accept`. Validation failure re-renders with errors, no redirect. Submit button template: `templates/components/OrderForm.html.twig:462` — `<button type="submit">Pokračovat k podpisu</button>` inside `{{ form_start(form, {attr:{novalidate, 'data-action':'submit->live#action:prevent','data-live-action-param':'submit'}}) }}` (lines ~23-28).
- `src/Twig/Components/AdminOnboardingForm.php` — `ComponentWithFormTrait`; `submit()` (lines ~252-370) returns `null` on validation failure, sets `$submitError` (LiveProp, line ~53) on business failures, redirects on success. Storage chosen via Konva map → `selectStorage()` (lines ~209-226). Submit button: `templates/components/AdminOnboardingForm.html.twig:354-360` — `<button type="button" data-action="live#action" data-live-action-param="submit" {% if not this.storageId %}disabled{% endif %}>Vytvořit onboarding</button>`. Root has `data-controller="admin-onboarding-bridge"` (line ~13).
- Errors render through `templates/form/tailwind_theme.html.twig` `form_errors` block (lines ~182-191) → `<div class="form-error">…</div>`; inputs get red border via `.form-group:has(> .form-error) .form-input` and `.form-input-error`.
- **Live Components are v3.0.0** (`symfony/ux-live-component` in `composer.lock`). v3 supports the `data-loading` directive scoped to a named action (`data-loading="action(submit)|addAttr(disabled)"`, `data-loading="action(submit)|show"`) and emits a `live:render` DOM event on the component root after every re-render. `assets/controllers.json` enables it (`fetch: lazy`).

### Plain Alpine forms (server-side validation, flash-toast errors)
- `src/Controller/Public/OrderAcceptController.php` — GET renders `public/order_accept.html.twig`; `handlePost()` (lines ~171-395) reads raw request booleans for consents/signature/place, accumulates `$errors[]`, on failure `addFlash('error', …)` per error and re-renders with a `submitted` array (preserves `signingPlace`/`acceptAll`/`acceptRecurring`). On success dispatches `GetOrCreateUserByEmailCommand` → `CreateOrderCommand` → `SignOrderCommand` → `AcceptOrderTermsCommand`, then `redirectToRoute('public_order_payment', {id: order.id})` (line ~360). **No duplicate guard.**
- `templates/public/order_accept.html.twig` — Alpine root state `{ signed, signingPlace, acceptAll, acceptRecurring }` (lines ~43-51); signature pad via `data-controller="signature"` writing hidden `signature_data`/`signing_method`/`typed_name`/`style_id`; master consent checkbox `x-model="acceptAll"` gating hidden mirror inputs `:disabled="!acceptAll"`; dedicated recurring checkbox `x-model="acceptRecurring"` (AUTO_RECURRING only). Submit button `templates/public/order_accept.html.twig:537-545` — **`:disabled="!(signed && acceptAll && acceptRecurring && signingPlace.trim())"`**, label **`OBJEDNÁVÁM a zaplatím`** (compliance-locked, see below).
- `src/Controller/Public/CustomerSigningController.php` — `/podpis/{token}`; `handlePost()` (lines ~62-149) similar; dispatches `CustomerSignOnboardingCommand`, then redirects to complete/debt/payment. Template `templates/public/customer_signing.html.twig`; submit button (lines ~256-261) `:disabled="!(signed && acceptAll && signingPlace.trim())"`, label **`Podepsat smlouvu`**.
- `assets/controllers/signature_controller.js` dispatches `signature:signed` / `signature:cleared` window events and keeps hidden `signature_data` authoritative.

### Compliance (DO NOT BREAK — `.claude/COMPLIANCE.md`)
- `/prijmout` submit button label MUST remain exactly `OBJEDNÁVÁM a zaplatím` **at rest**. (A transient "Odesílám…" + spinner while the in-flight submit completes is acceptable — the binding action has already fired.)
- The dedicated recurring-payment consent checkbox, parameter card, identification block, card/3DS/GoPay logos, `vč. DPH` pricing must all stay exactly where they are. This spec only touches the *submit/validation/scroll* mechanics, never the consent structure or legal copy.

### Test infra
- `symfony/panther ^2.2` is installed but **unused**. Per decision, JS behaviors are covered by a **manual QA checklist** (below); server-side guarantees (idempotency token, validation, idempotent signing) get **PHPUnit integration tests** (DAMA DoctrineTestBundle, MockClock fixed `2025-06-15 12:00:00 UTC`). Do **not** stand up a Panther harness in this spec.

## Architecture

Two new Stimulus controllers, each owning one architecture; one server-side token; one idempotent-signing guard.

```
PLAIN FORMS (/prijmout, /podpis)          LIVE COMPONENTS (order form, onboarding)
  form_guard_controller.js                   live_form_scroll_controller.js
   ├─ submit (capture): scan required        ├─ markSubmit(): set "expecting submit" flag
   │   targets; if any empty/invalid →        │   (wired as 2nd action on the submit button)
   │   preventDefault + scroll+focus first    ├─ on live:render: if flag set AND component
   │   + show summary line                    │   now contains .form-error/.form-input-error
   ├─ if all valid → lock form (data-         │   → scroll+focus first; clear flag
   │   submitting), disable button, swap      └─ double-submit handled natively by Live:
   │   to spinner + "Odesílám…"                   data-loading="action(submit)|…" on button
   └─ second submit blocked by the flag           + spinner span
   (server backstop ↓)
ORDER ACCEPT: one-time submit_token in session (popped per POST) → duplicate of a
  successful submit finds no token → redirect to the already-created order's payment page.
CUSTOMER SIGNING: top-of-POST guard → if order already signed, redirect to next step.
```

Shared scroll behavior (both controllers): smooth `scrollIntoView`-style scroll with a **96px** top offset (matches `order_selection_mode_controller.js`), then `focus({preventScroll:true})` on the field. "First" = first matching element in **DOM order** that is **actually visible** (`offsetParent !== null` / non-zero client rects), so conditionally-hidden sections (company block, `endDate`, recurring card, etc.) are skipped.

## Requirements

### 1. New `assets/controllers/form_guard_controller.js` (plain forms)

Generic, declarative; no knowledge of Alpine. Reads real DOM values so it stays correct regardless of Alpine state.

```js
import { Controller } from '@hotwired/stimulus';

// data-controller="form-guard"
// targets:
//   required  → an input/checkbox/hidden whose value must be non-empty (text) or checked (checkbox)
//   summary   → the inline feedback line near the submit button (hidden at rest)
//   submit    → the submit <button>
//   spinner   → spinner element inside the button (hidden at rest)
//   label     → the button text node wrapper (to swap to "Odesílám…")
// values:
//   offset: Number (default 96)
//   submittingText: String (default "Odesílám…")
export default class extends Controller {
  static targets = ['required', 'summary', 'submit', 'spinner', 'label'];
  static values = { offset: { type: Number, default: 96 }, submittingText: { type: String, default: 'Odesílám…' } };

  connect() {
    this.submitting = false;
    // capture phase so we run before the browser submits
    this.element.addEventListener('submit', this.onSubmit, true);
  }
  disconnect() { this.element.removeEventListener('submit', this.onSubmit, true); }

  onSubmit = (event) => {
    if (this.submitting) { event.preventDefault(); event.stopImmediatePropagation(); return; }

    const firstInvalid = this.firstInvalidTarget();
    if (firstInvalid) {
      event.preventDefault();
      event.stopImmediatePropagation();
      this.showSummary();
      this.scrollTo(firstInvalid);
      return;
    }
    // valid → lock and let the native submit proceed
    this.lock();
  };

  firstInvalidTarget() {
    return this.requiredTargets.find((el) => this.isVisible(el) && !this.isFilled(el));
  }
  isFilled(el) {
    if (el.type === 'checkbox' || el.type === 'radio') return el.checked;
    return String(el.value ?? '').trim() !== '';
  }
  isVisible(el) {
    // climb to the labelled wrapper if marked, else the element itself
    const probe = el.closest('[data-form-guard-scroll]') || el;
    return probe.offsetParent !== null && probe.getClientRects().length > 0;
  }
  scrollTo(el) {
    const anchor = el.closest('[data-form-guard-scroll]') || el;
    const top = anchor.getBoundingClientRect().top + window.scrollY - this.offsetValue;
    window.scrollTo({ top, behavior: 'smooth' });
    anchor.classList.add('form-guard-flash'); // brief red ring; auto-removed
    setTimeout(() => anchor.classList.remove('form-guard-flash'), 1600);
    const focusable = el.matches('input,select,textarea,button') ? el : el.querySelector('input,select,textarea');
    focusable?.focus?.({ preventScroll: true });
  }
  showSummary() { this.hasSummaryTarget && this.summaryTarget.classList.remove('hidden'); }
  lock() {
    this.submitting = true;
    if (this.hasSubmitTarget) this.submitTarget.disabled = true;
    if (this.hasSpinnerTarget) this.spinnerTarget.classList.remove('hidden');
    if (this.hasLabelTarget) this.labelTarget.textContent = this.submittingTextValue;
  }
}
```

Notes:
- `stopImmediatePropagation` so a stray Alpine/native handler can't submit anyway.
- The hidden mirror inputs on `/prijmout` are `:disabled` by Alpine when their consent is unchecked, so they must NOT be the `required` targets (a disabled input reads as "not filled" only while unchecked — acceptable, but cleaner to mark the **visible** controls). Mark the **visible** master checkbox, the **visible** recurring checkbox (when rendered), the **visible** `signing_place` text input, and the **hidden `signature_data`** input (authoritative signature value, always present, set by `signature_controller`). For the signature, give it `data-form-guard-scroll` pointing at the signature section wrapper and a label.

### 2. New `assets/controllers/live_form_scroll_controller.js` (Live Components)

```js
import { Controller } from '@hotwired/stimulus';

// data-controller="live-form-scroll" on the COMPONENT ROOT
// values: offset: Number (default 96)
// Wire markSubmit as the SECOND action on the submit button:
//   data-action="live#action live-form-scroll#markSubmit"
export default class extends Controller {
  static values = { offset: { type: Number, default: 96 } };
  connect() {
    this.expecting = false;
    this.onRender = this.onRender.bind(this);
    this.element.addEventListener('live:render', this.onRender);
  }
  disconnect() { this.element.removeEventListener('live:render', this.onRender); }
  markSubmit() { this.expecting = true; }
  onRender() {
    if (!this.expecting) return;
    this.expecting = false;
    const first = [...this.element.querySelectorAll('.form-error, .form-input-error, [data-live-error]')]
      .find((el) => el.offsetParent !== null && el.getClientRects().length > 0);
    if (!first) return; // no errors → success path already redirected
    const anchor = first.closest('.form-group, [data-form-guard-scroll]') || first;
    const top = anchor.getBoundingClientRect().top + window.scrollY - this.offsetValue;
    window.scrollTo({ top, behavior: 'smooth' });
    (anchor.querySelector('input,select,textarea') || anchor).focus?.({ preventScroll: true });
  }
}
```

Notes:
- v3 dispatches `live:render` on the component root after each morph. The `expecting` flag ensures we scroll **only after a submit attempt**, never after a per-field blur validation render.
- If `live:render` proves unreliable in practice, the implementer may instead use the v3 component hook `this.component = getComponent(this.element); this.component.on('render:finished', cb)` — same logic. Pick whichever the installed v3 exposes; document the choice in a code comment.

### 3. Tailwind: flash-ring utility

Add to `assets/styles/app.css` (near the existing `.form-error` rules):

```css
.form-guard-flash { @apply ring-2 ring-red-400 ring-offset-2 rounded transition; }
```

### 4. Order form template (`templates/components/OrderForm.html.twig`)

- Add `data-controller="live-form-scroll"` to the component root element (the outermost div that already carries the Live controller; if the component root *is* the `<form>`, add it there — verify and place it on the element that receives `live:render`).
- Submit button: add `markSubmit` and double-submit affordance:

```twig
<button type="submit"
        class="btn btn-primary btn-lg"
        data-action="live#action live-form-scroll#markSubmit"
        data-live-action-param="submit"
        data-loading="action(submit)|addAttr(disabled)">
    <span data-loading="action(submit)|show" class="hidden mr-2"><svg class="animate-spin h-5 w-5" …spinner…></svg></span>
    <span data-loading="action(submit)|hide">Pokračovat k podpisu</span>
    {# keep the existing arrow svg, also wrapped so it hides during loading #}
</button>
```

(The form already wires `submit->live#action:prevent`; adding `markSubmit` to the *button* action keeps both paths covered. Keep `:prevent` on the form-level action.)
- Add an error-summary line directly above the button, shown only when the rendered form has errors:

```twig
{% if form.vars.submitted and not form.vars.valid %}
  <p class="form-error" role="alert">Některá pole nejsou vyplněná nebo obsahují chybu — opravte zvýrazněná pole výše.</p>
{% endif %}
```

### 5. Admin onboarding template + component

`templates/components/AdminOnboardingForm.html.twig`:
- Add `data-controller="live-form-scroll"` to the component root (it already has `admin-onboarding-bridge`; chain them: `data-controller="admin-onboarding-bridge live-form-scroll"`).
- **Remove the `{% if not this.storageId %}disabled{% endif %}`** from the submit button. Replace with the new model:

```twig
<button type="button"
        data-action="live#action live-form-scroll#markSubmit"
        data-live-action-param="submit"
        data-loading="action(submit)|addAttr(disabled)"
        class="btn btn-primary btn-lg">
    <span data-loading="action(submit)|show" class="hidden mr-2"><svg class="animate-spin h-5 w-5" …></svg></span>
    <span data-loading="action(submit)|hide">Vytvořit onboarding</span>
</button>
```

- Storage-not-selected feedback: render an error anchor at the storage/map section so the scroll lands there, not on the top banner. Add to the map section wrapper:

```twig
{% if this.storageError %}
  <p class="form-error" data-live-error role="alert">{{ this.storageError }}</p>
{% endif %}
```

`src/Twig/Components/AdminOnboardingForm.php`:
- Add `#[LiveProp] public ?string $storageError = null;`.
- In `submit()`, **before** dispatching the command, reset `$this->storageError = null; $this->submitError = null;`. If `storageId` is empty (or fails the existing storage/type/place checks), set `$this->storageError = 'Vyberte skladovací jednotku na mapě.';` (or the matching existing message) and `return null;` — so `live:render` finds a visible `[data-live-error]` and scrolls to the map. Keep `$this->submitError` for genuine server/business failures unrelated to storage (e.g. handler exception), rendered in the existing top banner (also matched by the scroll selector).
- Keep `validateField()`/cascading actions untouched.

### 6. Order accept — server one-time token + client guard

`src/Controller/Public/OrderAcceptController.php`:
- **GET render:** generate `$token = $this->identityProvider->next()->toRfc4122()` (or `bin2hex(random_bytes(16))`), store `$session->set('order_accept_submit_token', $token)`, pass `submitToken: $token` to the template.
- **POST, at the very top of `handlePost()` (before reading consents):**

```php
$session = $request->getSession();
$expected = $session->get('order_accept_submit_token');
$provided = $request->request->getString('submit_token');
// Pop the token: every POST consumes the current token.
$session->remove('order_accept_submit_token');

if ($expected === null || !hash_equals((string) $expected, $provided)) {
    // Duplicate or stale submit. If we already created the order in a prior POST, send them there.
    $lastOrderId = $session->get('order_accept_last_order_id');
    if ($lastOrderId !== null) {
        $this->addFlash('info', 'Vaše objednávka už byla odeslána.');
        return $this->redirectToRoute('public_order_payment', ['id' => $lastOrderId]);
    }
    $this->addFlash('error', 'Platnost formuláře vypršela, zkuste objednávku odeslat znovu.');
    return $this->redirectToRoute('public_order_create', [
        'placeId' => $place->id, 'storageTypeId' => $storageType->id, 'storageId' => $storage->id,
    ]);
}
```

- **On validation failure** (the existing `$errors` non-empty branch): issue a **fresh** token before re-rendering (`$session->set('order_accept_submit_token', $newToken)` and pass it to the view) so the user can correct and resubmit.
- **On success:** after the order is created, `$session->set('order_accept_last_order_id', (string) $order->id)` before the redirect. (No new token needed — success redirects away.)

`templates/public/order_accept.html.twig`:
- Add hidden field inside the `<form>`: `<input type="hidden" name="submit_token" value="{{ submitToken }}">`.
- Add `data-controller="form-guard"` to the `<form>` (the form currently lives under the `signature` controller root — chain or nest; ensure `form-guard` is on the `<form>` element itself).
- **Remove** the Alpine `:disabled` and `:class` opacity binding from the submit button. Keep label `OBJEDNÁVÁM a zaplatím` at rest; restructure for spinner + lock:

```twig
<button type="submit"
        class="btn btn-primary btn-lg"
        data-form-guard-target="submit">
    <span class="hidden mr-2" data-form-guard-target="spinner"><svg class="animate-spin h-5 w-5" …></svg></span>
    <svg …existing card svg…></svg>
    <span data-form-guard-target="label">OBJEDNÁVÁM a zaplatím</span>
</button>
```

- Mark required targets (visible controls + authoritative signature hidden input):
  - master consent checkbox: `data-form-guard-target="required" data-form-guard-scroll` on its wrapper, with the checkbox carrying the target.
  - recurring consent checkbox (only inside the AUTO_RECURRING block): `data-form-guard-target="required"`, wrapper `data-form-guard-scroll`.
  - `signing_place` input: `data-form-guard-target="required"`.
  - hidden `signature_data`: `data-form-guard-target="required"`, plus `data-form-guard-scroll` on the signature section wrapper so the scroll lands on the pad.
- Add the summary line above the button: `<p class="form-error hidden" data-form-guard-target="summary" role="alert">Některá pole nejsou vyplněná — doplňte zvýrazněná pole.</p>`.
- Alpine state stays for the cosmetic "Podpis přidán" indicator; only the *gating* moves to `form-guard`.

### 7. Customer signing — idempotent guard + client guard

`src/Controller/Public/CustomerSigningController.php`:
- At the **top of the action / `handlePost()`**, if the order is already signed (use the existing signed/has-signature check that the entity exposes — `$order->hasSignature()` / contract signed state; verify the accessor), short-circuit: redirect to the same next-step routing the success path uses (complete / debt / payment) **without** re-dispatching `CustomerSignOnboardingCommand`. This makes a second POST (or a refresh) a safe no-op redirect rather than an exception.

`templates/public/customer_signing.html.twig`:
- Same `form-guard` treatment as §6 (no `submit_token` — the order already exists and the idempotent guard covers duplicates). Remove the `:disabled` binding; mark master consent + `signing_place` + hidden `signature_data` as required; add spinner + summary; keep label `Podepsat smlouvu`.

### 8. Importmap / controllers registration

Stimulus controllers in `assets/controllers/` are auto-registered (no `controllers.json` edit needed for app controllers). Confirm both new files are picked up by the existing `@symfony/stimulus-bundle` loader; no `importmap.php` change expected.

## Acceptance

- [ ] `composer quality` green; **also run `composer test`** (controller/template/Live changes are integration-covered — see memory note: `composer quality` only runs unit tests).
- [ ] **Order accept duplicate guard (integration test):** POST a valid accept → exactly one `Order` created, redirect to `public_order_payment`. Re-POST with the *same* `submit_token` → **no second order**, redirect to the existing order's payment page with the info flash. POST with missing/garbage token and no prior order → redirect to `public_order_create`.
- [ ] **Order accept validation re-issues token (integration test):** POST with a consent missing → no order, errors flashed, response carries a *new* `submit_token`; a follow-up POST with that new token + complete data succeeds.
- [ ] **Customer signing idempotency (integration test):** POST sign on an already-signed order → no exception, no duplicate side effects, redirect to the next-step route.
- [ ] **Onboarding storage feedback:** submitting with no storage selected sets `storageError`, returns null (no redirect), and the error renders at the map section (`[data-live-error]`); the submit button is no longer `disabled`.
- [ ] Existing form-data validation unit tests still pass; add a case asserting `AdminOnboardingForm::submit()` sets `storageError` when `storageId` is null (component/unit level if feasible, else cover via the controller path).
- [ ] **Manual QA checklist (all four forms), documented in the PR description:**
  1. Click submit with a required field empty → page smooth-scrolls to that field (navbar not covering it), field is focused + flashes a red ring, summary line appears. Works for a field inside a conditionally-shown section, and a hidden/irrelevant section is never targeted.
  2. Fill everything, submit once → button shows spinner + "Odesílám…" and is disabled; rapid second click does nothing; no duplicate order/sign.
  3. `/prijmout`: with JS disabled, a double-POST (e.g. resend) creates only one order (token backstop).
  4. Order form & onboarding: a failed submit scrolls to the first inline error; a single blur-validation does **not** scroll.
  5. Onboarding: submit with no storage → scrolls to the map with the "Vyberte skladovací jednotku" message; button is clickable (not greyed).
  6. `/prijmout` button still reads exactly `OBJEDNÁVÁM a zaplatím` at rest; recurring consent card/checkbox, logos, identification unchanged.

## Out of scope

- **Converting `/prijmout` or `/podpis` to Live Components** — decided against; they stay plain forms (lower risk on compliance-locked surfaces, and plain POSTs are far easier to integration-test). The shared client controller + server token deliver the same UX.
- **Per-field inline errors on `/prijmout`/`/podpis`** — the required cases (consents/signature/place) are fully checkable client-side and scrolled-to before any round-trip; server errors remain flash toasts as a backstop. Re-architecting these flat string errors into bound field errors is unnecessary churn.
- **Panther / browser test harness** — per decision; JS behaviors are a manual checklist.
- **Any change to consent structure, legal copy, button label at rest, recurring-payment parameters, logos, or pricing display** — governed by `.claude/COMPLIANCE.md`; untouched.
- **The blur-time server validation, cascading place→type→storage actions, ARES/address/datepicker/duration-preset controllers** — all keep working as-is; this spec only layers submit-time scroll/lock/feedback on top.
- **Idempotency tokens on the two Live Component forms** — Live's in-flight `data-loading` lock + the redirect-on-success (order form) and the natural guards (onboarding creates via command) are sufficient; no session token needed there.

## Open questions

None — proceed.
