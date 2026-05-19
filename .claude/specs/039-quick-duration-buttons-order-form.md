# 039 — Quick-duration buttons ("1 měsíc / 3 měsíce / 6 měsíců") on the order form

**Status:** done
**Type:** UX
**Scope:** small (~2 files: order form component template + new Stimulus controller; tiny CSS may piggy-back on existing `btn` utility classes)
**Depends on:** 007 (flatpickr everywhere), 008 (order form Live Component)

## Problem

On `/objednavka/{placeId}/{storageTypeId}/{storageId}` a customer who picks "Na dobu určitou" has to fill **both** flatpickr datepickers manually — startDate and endDate. The overwhelmingly common rentals are 1, 3, or 6 months from startDate; today the customer has to mentally add months and pick the matching day, which is friction for the single most common UX path. Long durations (12 months) are already capped server-side by `validateDates`.

## Goal

Three small inline buttons appear next to the endDate field while rentalType is `LIMITED`: **`1 měsíc`**, **`3 měsíce`**, **`6 měsíců`**. Clicking one sets `endDate = startDate + N months` in the form (both the visible flatpickr alt-input and the underlying hidden input update instantly; the Live Component price preview re-renders). Buttons are disabled until startDate is set. The buttons are convenience only — manual endDate picking still works.

## Context (current state)

- The order form lives in `templates/components/OrderForm.html.twig` (Live Component, see spec 008). The dates section is at lines 200–230.
- `OrderFormType` (`src/Form/OrderFormType.php:154-201`) builds `startDate` (DateType, always required) and `endDate` (DateType, required only when LIMITED). On `PRE_SET_DATA` the form re-adds `endDate` with `min-date = startDate + 7 days` so flatpickr won't offer too-short durations.
- `OrderFormData::validateDates` (`src/Form/OrderFormData.php:189-231`) enforces:
  - `endDate - startDate >= 7 days`
  - `endDate <= startDate + 1 year`
  - (so 1 / 3 / 6 months are all well inside both bounds — no rule conflict)
- The `endDate` block is only rendered when `rentalIsLimited` is true (`templates/components/OrderForm.html.twig:217-228`). For UNLIMITED the field is suppressed; the buttons must follow the same gate.
- `assets/controllers/datepicker_controller.js` wraps every date input in flatpickr. It exposes `this.picker` (the flatpickr instance) on its Stimulus controller; `picker.setDate(date, true)` updates both the hidden input value and the visible alt-input, and fires Flatpickr's onChange (which we route into Live UX through the existing `blur` forward at `datepicker_controller.js:66-70`).
- The Live Component morphs the DOM on every interaction. Stimulus controllers get re-attached, but our **own** controller doesn't hold state between morphs — it always reads fresh values from the inputs at click time, so morphing is harmless.
- The ARES button (`assets/controllers/ares_lookup_controller.js`) is the canonical example of a co-located Stimulus controller that reads from + writes to form fields inside a Live Component. Mirror its shape.

## Architecture

Pure client-side, mirroring the ARES button pattern. No new LiveAction, no FormType change, no server roundtrip — the buttons set the endDate flatpickr value directly, and Flatpickr's onChange propagates the new value through Live UX's form-value sync (which already drives the price preview's re-render).

```
[startDate flatpickr]  ─── value read at click time ──┐
                                                      │
[ "1 měsíc" "3 měsíce" "6 měsíců" ]  click ──────────►│
                                                      │   compute startDate + N months
                                                      │   (Date.setMonth, clamp last-of-month)
                                                      ▼
[endDate flatpickr] picker.setDate(newDate, true)
                                          │
                                          └─► Flatpickr's onChange + hidden input blur
                                                  │
                                                  └─► Live UX re-renders
                                                       (price preview, validation)
```

## Requirements

### 1. New Stimulus controller `assets/controllers/duration_preset_controller.js`

A small controller that owns the three buttons + a hint paragraph + a `disabled` state.

```js
import { Controller } from '@hotwired/stimulus';

const PRESETS = [1, 3, 6];

export default class extends Controller {
    static targets = ['button', 'hint'];

    connect() {
        this.boundSync = () => this.syncEnabledState();
        // The startDate flatpickr's hidden input is outside this controller's root
        // (it sits in its own form_row). Listen at document level so we catch
        // every value change, including programmatic setDate() calls.
        document.addEventListener('input', this.boundSync, true);
        document.addEventListener('change', this.boundSync, true);
        this.syncEnabledState();
    }

    disconnect() {
        document.removeEventListener('input', this.boundSync, true);
        document.removeEventListener('change', this.boundSync, true);
    }

    apply(event) {
        const months = Number(event.currentTarget.dataset.months);
        if (!PRESETS.includes(months)) return;

        const startInput = this.startInput();
        const endInput = this.endInput();
        if (!startInput || !endInput) return;

        const start = parseIsoDate(startInput.value);
        if (!start) return;

        const target = addMonthsSafe(start, months);
        const formatted = formatIso(target);

        // Prefer the flatpickr API on the endDate so the visible alt-input updates
        // and Flatpickr fires its own onChange. Fall back to plain DOM if the
        // picker is unavailable (Live UX mid-morph, very brief window).
        const datepickerCtrl = this.application.getControllerForElementAndIdentifier(endInput, 'datepicker');
        if (datepickerCtrl && datepickerCtrl.picker) {
            datepickerCtrl.picker.setDate(formatted, true);
        } else {
            endInput.value = formatted;
            endInput.dispatchEvent(new Event('input', { bubbles: true }));
            endInput.dispatchEvent(new Event('change', { bubbles: true }));
        }

        // Forward a blur so the Live Component's `validateField` action fires
        // and the endDate error (if any) renders / clears immediately.
        endInput.dispatchEvent(new Event('blur', { bubbles: true }));
    }

    startInput() {
        return document.querySelector('input[name$="[startDate]"]');
    }

    endInput() {
        return document.querySelector('input[name$="[endDate]"]');
    }

    syncEnabledState() {
        const hasStart = !!parseIsoDate(this.startInput()?.value ?? '');
        this.buttonTargets.forEach((btn) => {
            btn.disabled = !hasStart;
            btn.classList.toggle('opacity-50', !hasStart);
            btn.classList.toggle('cursor-not-allowed', !hasStart);
        });
        if (this.hasHintTarget) {
            this.hintTarget.hidden = hasStart;
        }
    }
}

function parseIsoDate(value) {
    if (!value) return null;
    const m = /^(\d{4})-(\d{1,2})-(\d{1,2})$/.exec(value.trim());
    if (!m) return null;
    const d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
    return isNaN(d.getTime()) ? null : d;
}

function addMonthsSafe(date, months) {
    // Calendar addition with last-of-month clamping (31 Jan + 1 month = 28/29 Feb,
    // not 3 Mar) — matches what a customer reading "1 měsíc" expects.
    const targetYear = date.getFullYear();
    const targetMonth = date.getMonth() + months;
    const targetDay = date.getDate();
    const result = new Date(targetYear, targetMonth, 1);
    const lastDay = new Date(result.getFullYear(), result.getMonth() + 1, 0).getDate();
    result.setDate(Math.min(targetDay, lastDay));
    return result;
}

function formatIso(d) {
    const yyyy = d.getFullYear();
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    return `${yyyy}-${mm}-${dd}`;
}
```

Notes:
- The `useCapture: true` on the document listeners is intentional — Live UX's own listeners can sometimes call `stopPropagation()` during a morph; capture phase ensures we still see the event.
- We deliberately don't try to surface an "active" highlight on whichever preset matches the current endDate. Reason: rounding edge cases (e.g. user picked startDate 31 Jan, "1 měsíc" = 28 Feb; manually picking exactly 28 Feb shouldn't visually claim to be "1 měsíc"). Cleaner to keep the buttons as one-way shortcuts.
- The selector `input[name$="[startDate]"]` deliberately uses attribute-suffix match so it works regardless of the form's root name (Symfony emits `order[startDate]`).

### 2. Template change in `templates/components/OrderForm.html.twig`

Inside the dates section (currently lines 200–230), wrap the existing `form_row(form.endDate, …)` rendering with a small container that hosts the buttons and lives next to it. The buttons render **only** when `rentalIsLimited` is true (same gate as the endDate field itself).

Sketch — replace the current `{% if rentalIsLimited %}` block (lines 217–228) with:

```twig
{% if rentalIsLimited %}
    <div data-controller="duration-preset">
        {{ form_row(form.endDate, {attr: {
            'data-action': 'blur->live#action',
            'data-live-action-param': 'validateField',
            'data-live-field-param': 'endDate',
        }}) }}

        <div class="mt-2 flex flex-wrap gap-2">
            <span class="text-xs text-gray-500 self-center mr-1">Rychlá volba:</span>
            {% for months in [1, 3, 6] %}
                <button type="button"
                        data-duration-preset-target="button"
                        data-action="duration-preset#apply"
                        data-months="{{ months }}"
                        class="btn btn-sm btn-outline">
                    {{ months }} {{ months == 1 ? 'měsíc' : (months < 5 ? 'měsíce' : 'měsíců') }}
                </button>
            {% endfor %}
        </div>

        <p data-duration-preset-target="hint" class="text-xs text-gray-500 mt-1" hidden>
            Nejdříve zvolte datum začátku.
        </p>
    </div>
{% else %}
    {# Suppress form_end's auto-render. validateDates skips endDate for unlimited rentals. #}
    {% do form.endDate.setRendered %}
{% endif %}
```

Notes:
- Czech inflection: 1 → `měsíc`, 2-4 → `měsíce`, 5+ → `měsíců`. The `months < 5` switch covers all three values; if the preset list ever grows past 4, revisit.
- The `<span>` uses `mr-1` so it visually leads into the button group without a big gap.
- `btn btn-sm btn-outline` matches the codebase's existing utility classes (used throughout admin views). If `btn-outline` doesn't exist, use the plain `btn btn-secondary` or whatever the equivalent is — check `assets/styles/app.css` and follow the existing palette. Don't invent new Tailwind utilities.
- The hint paragraph is `hidden` by default; the controller toggles it.

### 3. No backend changes

- `OrderFormType`, `OrderFormData`, `OrderForm` (component class), and `OrderCreateController` are **untouched**.
- The buttons don't bypass validation — they go through the same `setDate` → input/change → Live UX form-sync path as a manual flatpickr click, so `validateDates`, `endDate ≤ startDate + 1 year`, and the `endDate ≥ startDate + 7 days` floor all still apply on submit. With max preset = 6 months, none of those bounds will ever be tripped by a button click.

### 4. Tests

- No new PHPUnit coverage (this is a pure client-side concern; the spec adds no PHP behavior).
- The existing `tests/Integration/Twig/Components/OrderFormTest.php` (per spec 008) keeps passing — the component class is untouched. Verify with `docker compose exec web composer test:integration` (or the umbrella `composer test`).

## Acceptance

- `docker compose exec web composer quality` is green.
- Manual flow on `/objednavka/{placeId}/{storageTypeId}/{storageId}` as a guest:
  1. Page loads with rentalType = LIMITED (default). The three buttons render to the right/below the endDate field. They are **disabled** (greyed out, non-clickable). The hint "Nejdříve zvolte datum začátku." is visible.
  2. Pick a startDate (e.g. 2026-06-01) in the startDate picker.
  3. All three buttons become enabled within ~50ms; the hint disappears.
  4. Click `3 měsíce`. The endDate flatpickr instantly shows `1. 9. 2026` (visible alt-input) AND its hidden input value is `2026-09-01`. The price-preview block below re-renders to show the new schedule.
  5. Pick startDate = 2026-01-31, click `1 měsíc`: endDate displays `28. 2. 2026` (last-of-month clamp), NOT `3. 3. 2026`.
  6. Switch rentalType to UNLIMITED: the entire endDate block (field + buttons + hint) disappears.
  7. Switch back to LIMITED: the block reappears; if startDate is still set from before, buttons are enabled.
  8. Click `6 měsíců` → submit the form → reach `/prijmout` with the chosen endDate carried forward in `order_form_data` session.
- Browser console is silent (no errors, no warnings) throughout.
- No new HTTP requests are made on button click (verify in network tab — pure client-side).

## Out of scope

- **Highlighting "the active preset"** when endDate happens to match startDate + N months. Reason: ambiguous with last-of-month clamping (a manually-picked Feb 28 isn't necessarily "1 měsíc from Jan 31"). Adds noise for no win.
- **A 12-month preset.** Server cap is exactly 1 year (`endDate <= startDate + 1 year`); on a 31-day month boundary the preset could land 1 day past the cap and fail validation. Easy to add later if requested; keeping to 1/3/6 keeps it bulletproof.
- **Touching the startDate field** with similar "od dneška / od pondělí" presets. Different scope; user only asked for endDate convenience.
- **Persisting the user's last-used preset across sessions.** No real value.
- **Showing the preset buttons on the admin onboarding forms** (`AdminCreateOnboardingFormType`, `AdminMigrateCustomerFormType`). Admins type dates fast and aren't the friction-sensitive audience here.
- **A server-side `LiveAction` alternative.** Considered and rejected — would need to mutate `ComponentWithFormTrait::$formValues` and trigger a re-bind; the existing `validateField` action already proves the form-sync path works for client-driven changes, so a Stimulus controller is simpler, matches the ARES button precedent, and avoids a server round-trip per click.
- **i18n.** UI is Czech only across this app; no plural-rule abstraction needed for three hardcoded values.

## Open questions

None — proceed.
