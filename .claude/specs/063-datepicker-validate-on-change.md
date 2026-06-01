# 063 — Re-validate date fields on calendar pick (clear stale errors without an extra click)

**Status:** done
**Type:** UX (bug fix)
**Scope:** tiny (1 file: `assets/controllers/datepicker_controller.js`)
**Depends on:** none

## Problem

On the Live order form, picking a date from the Flatpickr calendar leaves a **stale validation error** visible until the user clicks elsewhere. Repro: open the start-date picker, click a valid future date (e.g. `10. 6. 2026`) → the field still shows "Datum začátku nemůže být v minulosti" (a leftover from the prior, invalid/empty state) → only a second click *outside* the field clears it.

Cause: the date fields wire per-field live validation on **blur** (`templates/components/OrderForm.html.twig:246-248` etc.: `data-action="blur->live#action"`, `validateField`). The datepicker controller already forwards the visible alt-input's `blur` to the hidden input for the *manual-typing-then-tab-away* path (`assets/controllers/datepicker_controller.js:66-70`). But **selecting a date in the calendar fires Flatpickr's `onChange`, not a `blur`** — the alt-input keeps focus after the picker closes — so `validateField` never re-runs against the newly chosen (valid) value, and the old error stays. The user's "extra click" is what finally blurs the field and triggers re-validation.

## Goal

Choosing a date from the calendar immediately re-runs the same per-field live validation that a blur would — the stale error disappears the instant a valid date is picked, with no extra click. Manual typing + tab-away keeps working exactly as today.

## Context (current state)

- `assets/controllers/datepicker_controller.js` — shared Stimulus controller wrapping Flatpickr (`altInput: true`, `dateFormat: 'Y-m-d'` on the hidden original = `this.element`). It already dispatches a synthetic `blur` on `this.element` when the visible alt-input blurs (lines 66-70). There is **no** `onChange` handler in the Flatpickr `config` (lines 24-39), so a calendar selection produces no event on the hidden input that Live UX listens to.
- `templates/components/OrderForm.html.twig` — `startDate` (246-248), `endDate` (254-256) and `birthDate` (77-79) date widgets carry `data-action="blur->live#action"` + `data-live-action-param="validateField"` + their `data-live-field-param`. These are the live-validated date fields affected by the bug.
- Live UX serializes the whole form's current DOM input values when a `live#action` fires, so as long as the hidden input's value is updated **before** we dispatch (Flatpickr sets `this.element.value` synchronously during selection, *then* calls `onChange`), `validateField` re-validates against the freshly picked date.
- The onboarding form's date fields (`AdminOnboardingForm.html.twig`) are rendered with plain `form_row` and are **not** live-validated today, so they have no blur binding to trigger — the fix is a harmless no-op there (a dispatched event with no matching `data-action`). No template changes needed.

## Requirements

### 1. Dispatch a validation-trigger event on date selection

File: `assets/controllers/datepicker_controller.js`, inside `initializeDatepicker()`'s `config` object.

Add an `onChange` callback that re-fires the same event the blur path uses, so the existing `blur->live#action validateField` binding runs on calendar pick:

```js
const config = {
    locale: Czech,
    dateFormat: 'Y-m-d',
    altInput: true,
    altFormat: 'j. n. Y',
    altInputClass: 'form-input',
    allowInput: true,
    disableMobile: true,
    parseDate: this.parseDate,
    // A calendar selection fires onChange but no blur (focus stays on the
    // alt-input after the picker closes), so the blur-wired live validation
    // never re-runs and a stale error lingers until the user clicks away.
    // Forward it to the same blur the manual-typing path already triggers.
    // this.element.value is updated by flatpickr before onChange fires, so
    // validateField re-validates against the picked date.
    onChange: () => {
        this.element.dispatchEvent(new Event('blur'));
    },
};
```

Notes for the implementer:
- Keep the existing alt-input → hidden-input blur forward (lines 66-70); the two paths are complementary (typing vs. clicking) and double-validation is idempotent/harmless.
- Dispatch `blur` (not a focus change) — the hidden original is off-screen and unfocused, so this only triggers the Stimulus action; it does not visually steal focus or fight Flatpickr. This mirrors the pattern already used on line 68.
- Do **not** change the templates. Reusing the existing `blur` binding keeps the fix in one place and automatically covers `startDate`, `endDate`, and `birthDate`.
- Flatpickr's `onChange` does **not** fire for `defaultDate` set during init, so re-instantiation on Live morph won't spuriously trigger validation.

## Acceptance

- [ ] Order form: open the start-date picker and click a valid future date → the "v minulosti" error clears **immediately**, no second click required.
- [ ] Order form: picking an end date before/too-close-to the start date shows its validation error immediately on pick (validation still runs; only the timing changed).
- [ ] Manual typing a date and tabbing away still validates as before (no regression on the alt-blur path).
- [ ] Birth-date field behaves the same (immediate validation on pick).
- [ ] No console errors; the Flatpickr calendar still closes normally on selection.
- [ ] `composer quality` is green (JS-only change; no PHP affected).

## Out of scope

- **Adding live validation to the onboarding form's date fields.** They aren't live-validated today; this fix only changes *when* already-wired validation runs. (If wanted, that's a separate spec.)
- **Switching the date fields' template binding from `blur` to `change`.** Reusing the existing blur binding via the controller is less churn and covers both forms through the shared controller.
- **Any change to the Flatpickr locale/parser/min-max-date logic** — untouched.

## Open questions

None — proceed.
