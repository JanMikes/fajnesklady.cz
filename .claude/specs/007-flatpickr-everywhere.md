# 007 — Flatpickr on every date input (forms + filters)

**Status:** done
**Type:** UX (frontend wiring + small form-theme refactor)
**Scope:** small (~6 files: form theme override + 3 FormTypes + email-log filter template + unavailability inline JS fix)
**Depends on:** none

## Problem

Native HTML5 `<input type="date">` is currently used almost everywhere we ask for a date: 4 FormTypes (Order, StorageUnavailability, AdminCreateOnboarding, AdminMigrateCustomer) and the admin email-log filter. The native picker is locale-inconsistent (different look on every browser/OS), week-starts-on-Sunday on some platforms, and rejects partial typing. The OrderForm's start/end fields already opt into a flatpickr controller (`data-controller="datepicker"`) — we want that consistent experience on every date input in the app.

## Goal

Every visible date input in the application is rendered by flatpickr — Czech locale, week starts Monday, format `j. n. Y` shown to the user, `Y-m-d` submitted to the server. Native browser pickers do not appear (suppressed by flatpickr's `altInput`/`disableMobile`). One Stimulus controller drives all of them; FormTypes don't need to opt in individually.

## Context (current state)

- `assets/controllers/datepicker_controller.js` (already exists, 47 lines): wraps flatpickr with `locale: Czech`, `dateFormat: 'Y-m-d'`, `altInput: true`, `altFormat: 'j. n. Y'`, `disableMobile: true`. Supports values `min-date`, `max-date`, `default-date` via Stimulus values.
- `importmap.php`: flatpickr 4.6.13 + CSS + Czech locale already declared. No JS install step needed.
- `templates/form/tailwind_theme.html.twig`: project form theme; already overrides `password_widget`, `textarea_widget`, etc. Does not currently override `date_widget`.
- `config/packages/twig.php`: registers `form/tailwind_theme.html.twig` as the global form theme.
- FormTypes that use `DateType` (all `widget: 'single_text'` — verified, no `widget: 'choice'` variants exist):
  - `src/Form/OrderFormType.php:56` — `birthDate` (no datepicker yet, no min/max)
  - `src/Form/OrderFormType.php:131,140` — `startDate` / `endDate`, **already** opts in via `'data-controller' => 'datepicker'` plus `'data-datepicker-min-date-value' => tomorrow`
  - `src/Form/StorageUnavailabilityFormType.php:39,44` — `startDate` / `endDate`
  - `src/Form/AdminCreateOnboardingFormType.php:51,101,105` — `birthDate` / `startDate` / `endDate`
  - `src/Form/AdminMigrateCustomerFormType.php:52,102,106,120` — `birthDate` / `startDate` / `endDate` / `paidAt`
- Raw HTML date inputs (only one place): `templates/admin/email_log/list.html.twig:16,20` — `<input type="date" name="date_from">` and `<input type="date" name="date_to">`. Controller passes `currentDateFrom` / `currentDateTo` as ISO `Y-m-d` strings (`AdminEmailLogController.php:63-64`).
- No `DateTimeType` or `<input type="datetime-local">` exists anywhere — date-only scope.
- **Gotcha** — `templates/portal/unavailability/create.html.twig:71-86` has an inline `<script>` that toggles the endDate field based on the "indefinite" checkbox. It does `endDateField.disabled = …` and `endDateField.value = ''`. Once flatpickr replaces the visible input with an `altInput` text field, those operations target the *hidden* original input and have no visible effect. The script must be updated to drive the flatpickr instance instead.

## Architecture

```
[Symfony renders DateType, single_text widget]
        │
        ▼
form theme `date_widget` block (overridden) — adds data-controller="datepicker"
        │
        ▼
Stimulus auto-attaches → datepicker_controller.js → flatpickr() initialized
        │
        ▼
Visible:  <input type="text"> (Czech format "1. 6. 2026", calendar pops on focus)
Hidden:   <input type="date" name="..."> (ISO "2026-06-01" — what the server reads)
```

The same controller is also used on the two raw inputs in the email-log filter, wired via `data-controller="datepicker"` directly on the `<input>` element.

## Requirements

### 1. Override `date_widget` in the form theme

Edit `templates/form/tailwind_theme.html.twig`. Add a `date_widget` block that, for the `single_text` variant, merges `data-controller="datepicker"` into the input's attributes — preserving any existing `data-controller` or other attrs that the FormType already set:

```twig
{# Date widget — single_text variant gets the flatpickr Stimulus controller automatically #}
{% block date_widget -%}
    {% if widget == 'single_text' -%}
        {% set existing = attr['data-controller']|default('') %}
        {% set attr = attr|merge({
            'data-controller': (existing ~ ' datepicker')|trim,
        }) %}
        {{- block('form_widget_simple') -}}
    {%- else -%}
        {{- parent() -}}
    {%- endif %}
{%- endblock date_widget %}
```

Why merge instead of replace: a FormType might already set `data-controller="something-else"` (none currently do, but the merge keeps composition open) or other `data-datepicker-*-value` attrs (OrderForm does — `min-date-value`).

The `else` branch falls back to the parent `date_widget` block — handles the `widget: 'choice'` (3-dropdowns) variant if anyone ever uses it. Today no FormType does, but the fallback keeps the override safe.

### 2. Remove the now-redundant `data-controller` from `OrderFormType`

In `src/Form/OrderFormType.php`, drop the `'data-controller' => 'datepicker'` lines from `startDate` (line 136) and `endDate` (line 146) — the form theme override now adds it. **Keep** the `'data-datepicker-min-date-value'` attribute on both fields (still needed to forbid past dates).

### 3. Constrain `birthDate` to past dates only

A future birthday is invalid input. Add `'data-datepicker-max-date-value'` set to today's ISO date on every `birthDate` field. Use `(new \DateTimeImmutable('today'))->format('Y-m-d')` so it re-evaluates on every form render (no stale cached value).

Files:
- `src/Form/OrderFormType.php:56` (`birthDate`)
- `src/Form/AdminCreateOnboardingFormType.php:51` (`birthDate`)
- `src/Form/AdminMigrateCustomerFormType.php:52` (`birthDate`)

Pattern (matches what OrderForm already does for start/end):

```php
->add('birthDate', DateType::class, [
    'label' => 'Datum narození',
    'widget' => 'single_text',
    'input' => 'datetime_immutable',
    'attr' => [
        'autocomplete' => 'bday',
        'data-datepicker-max-date-value' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
    ],
    // … keep existing options
])
```

For `OrderFormType`, merge into the existing `attr` (which has `autocomplete`). For the two admin forms, the existing `attr` is empty/absent — add the array.

No min-date — birth dates from any prior year are valid.

### 4. Wire flatpickr on the email-log filter inputs

Edit `templates/admin/email_log/list.html.twig` lines 16 and 20. Add `data-controller="datepicker"` to both:

```twig
<input type="date" name="date_from" value="{{ currentDateFrom }}"
       data-controller="datepicker"
       class="form-input text-sm py-1.5">
…
<input type="date" name="date_to" value="{{ currentDateTo }}"
       data-controller="datepicker"
       class="form-input text-sm py-1.5">
```

The `value="{{ currentDateFrom }}"` is already ISO `Y-m-d` (controller passes it that way at `AdminEmailLogController.php:63-64`), which is exactly what flatpickr's `dateFormat: 'Y-m-d'` reads on init — no controller-side change needed.

The flatpickr alt-input takes over the visible rendering; the original `<input type="date">` becomes hidden and keeps the `name="date_from"` so the GET form still submits the same param shape. The Tailwind sizing classes (`text-sm py-1.5`) live on the original input — flatpickr's altInput copies its `className`, so sizing is preserved. **Verify visually** during acceptance and tweak with a small CSS rule on `input.flatpickr-alt-input` if needed (no rule expected; just check).

### 5. Fix the unavailability "indefinite" toggle

Edit `templates/portal/unavailability/create.html.twig`, the inline `<script>` at lines 71–86. Once `endDate` is wrapped by flatpickr, `endDateField.value = ''` and `endDateField.disabled = …` only affect the *hidden* original input — the visible alt-input keeps showing the old value and remains clickable. Update the script to drive the flatpickr instance:

```js
document.addEventListener('DOMContentLoaded', function() {
    const indefiniteCheckbox = document.getElementById('{{ form.indefinite.vars.id }}');
    const endDateField = document.getElementById('{{ form.endDate.vars.id }}');

    function toggleEndDate() {
        const fp = endDateField._flatpickr;
        if (indefiniteCheckbox.checked) {
            if (fp) {
                fp.clear();
                fp.altInput.disabled = true;
                fp.set('clickOpens', false);
            } else {
                endDateField.value = '';
                endDateField.disabled = true;
            }
        } else {
            if (fp) {
                fp.altInput.disabled = false;
                fp.set('clickOpens', true);
            } else {
                endDateField.disabled = false;
            }
        }
    }

    indefiniteCheckbox.addEventListener('change', toggleEndDate);
    toggleEndDate();
});
```

The `if (fp) … else …` branches keep this working even if the Stimulus controller hasn't connected yet (race-safe; flatpickr initializes on `connect()` which runs before `DOMContentLoaded` finishes in practice, but defensive).

`fp.clear()` empties both the hidden original and the visible altInput. `fp.set('clickOpens', false)` prevents the calendar popup from appearing while the field is "disabled". `fp.altInput.disabled = true` greys the visible input.

### 6. (Optional) Confirm import path

The Stimulus controller imports `import 'flatpickr/dist/flatpickr.min.css'` (line 3). After `composer quality`, also run the dev server (`docker compose up`) once and check the browser console — no 404 for the CSS bundle. AssetMapper compiles it from the importmap entry already present.

## Acceptance

- `docker compose exec web composer quality` is green.
- Open these pages and verify the visible inputs show in flatpickr's Czech format (e.g. "1. 6. 2026"), open a calendar popup on focus, and the native browser date picker does NOT appear:
  - `/objednavka/.../prijmout` — `birthDate`, `startDate`, `endDate`
  - `/portal/unavailabilities/create` — `startDate`, `endDate`
  - `/portal/admin/onboarding/digital` — `birthDate`, `startDate`, `endDate`
  - `/portal/admin/onboarding/migrate` — `birthDate`, `startDate`, `endDate`, `paidAt`
  - `/portal/admin/email-log` — `date_from`, `date_to`
- All `birthDate` fields refuse to navigate to or pick a date in the future (today is the latest selectable day).
- Order form `startDate` and `endDate` still refuse dates before tomorrow (existing behavior preserved).
- Submitting any of those forms with picked dates persists the same value as before — server-side parsing untouched (still `Y-m-d`).
- On `/portal/unavailabilities/create`, ticking the "Neomezené" checkbox clears the endDate's visible text and disables the field; unticking re-enables it. Picking a date again still works.
- The email-log filter still submits `?date_from=2026-04-01&date_to=2026-04-30` — verify by inspecting the URL after clicking "Filtrovat".
- The week starts on Monday in every popup (Czech locale convention).
- No JS console errors on any of the pages above.

## Out of scope

- **Dynamically linking `endDate.minDate` to the picked `startDate`.** The OrderForm's static `min-date = tomorrow` on both fields stays as-is; cross-field linking (so endDate ≥ startDate at the picker level) is a separate UX feature, not part of "use a datepicker instead of native". Server-side validation already enforces ordering.
- **Time-of-day inputs / `DateTimeType`.** None exist in the codebase today. If one is added later, the form-theme override won't catch it (it overrides `date_widget`, not `datetime_widget`); a follow-up spec can extend.
- **Replacing the existing `datepicker_controller.js`.** Already correct shape — keep it.
- **Switching libraries** (e.g. to `air-datepicker`, `vanillajs-datepicker`). Flatpickr is in the importmap, working in the OrderForm, no reason to churn.
- **Adding a "Today" / "Clear" button to the picker UI.** Default flatpickr UX is fine.
- **Localizing the format per user preference.** Always Czech, always `j. n. Y`.
- **Date range pickers** (single picker that handles start + end). Two separate pickers with Czech labels are clearer for the use cases in this app.

## Open questions

None — proceed.
