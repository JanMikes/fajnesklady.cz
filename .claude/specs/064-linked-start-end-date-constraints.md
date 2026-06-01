# 064 — Link start/end date pickers (order + onboarding): end ≥ start+7d, disabled until start chosen

**Status:** done
**Type:** UX
**Scope:** small (2 Stimulus controllers + 2 templates)
**Depends on:** none (complements spec 063 — pick re-validation — and reuses the existing `duration-preset` controller)

## Problem

On both date-pair forms the start and end pickers are independent on the client, so the rental-duration rules are only enforced after the fact (an error message), not prevented in the picker. A user can open the **end** picker and choose a date before the start (or fewer than 7 days after it), pick a start that leaves an already-chosen end invalid, or clear the start and leave a dangling end. The fix wants the end picker to simply not offer invalid dates.

Two forms, slightly different rules (verified in code):

| Rule | Order form (`OrderFormData::validateDates`) | Admin onboarding (`AdminOnboardingFormData::validateDates`) |
|---|---|---|
| End required for LIMITED | yes | yes |
| Min rental | **≥ 7 days** | **≥ 7 days** |
| Max rental | **≤ start + 1 year** | **none** (admin may set longer) |
| Start in past | rejected (`startDate < today`) | **allowed** (admin may backdate) |

## Goal

Choosing a start date immediately constrains the end picker to **[start+7 days, max]** client-side (max = start+1 year on the order form, unbounded on onboarding), with no round-trip. The end picker is **disabled until a start date exists**. If a start change makes an already-picked end date invalid, the end date is **cleared** so the user re-picks within the valid range. Clearing the start disables the end picker again and clears its value. Server-side `validateDates` stays the source of truth (esp. for manually-typed dates).

## Context (current state)

- `assets/controllers/duration_preset_controller.js` — already the cross-field hub: listens for `input`/`change` at `document` level (capture), resolves `startInput()`/`endInput()` by `name$="[startDate]"`/`[endDate]"` (works for both `order_form[...]` and `admin_onboarding_form[...]` names — only one form per page), reaches the end picker via `this.application.getControllerForElementAndIdentifier(endInput, 'datepicker')`, and `syncEnabledState()` toggles the quick-buttons + hint on start presence. Its button/hint logic already **no-ops gracefully when those targets are absent** (`this.buttonTargets.forEach` over an empty array; `if (this.hasHintTarget)` guard) — so the same controller can wrap an end field that has *no* preset buttons.
- `assets/controllers/datepicker_controller.js` — wraps Flatpickr (`altInput: true`; visible input = `this.picker.altInput`, value-bearing hidden original = `this.element`). Exposes no retuning API yet; spec 063 adds an `onChange→blur` dispatch.
- **Order form** `templates/components/OrderForm.html.twig:251-275` — end field already wrapped in `<div data-controller="duration-preset">` with 1/3/6-month buttons + "Nejdříve zvolte datum začátku" hint (LIMITED only). startDate picker has `data-datepicker-min-date-value = today`.
- **Onboarding form** `templates/components/AdminOnboardingForm.html.twig:99-112` (the "Datum" card) — `startDate`/`endDate` are plain `form_row` in a 2-col grid, end rendered only for LIMITED (`{% do form.endDate.setRendered %}` otherwise). **No** `duration-preset` wrapper, no datepicker min on start (backdating allowed). Not live-validated — but the constraint behavior here is pure client-side Flatpickr and needs no live wiring.
- Server rules: `src/Form/OrderFormData.php:214-255` and `src/Form/AdminOnboardingFormData.php:159-185` (numbers mirrored above).

## Requirements

### 1. Expose picker-retuning methods on the datepicker controller

File: `assets/controllers/datepicker_controller.js`. Add public methods that encapsulate Flatpickr internals so `duration-preset` never touches `this.picker` directly beyond these:

```js
// Set/relax the selectable range. Pass null to clear a bound (e.g. no max).
setRange(minDate, maxDate) {
    if (!this.picker) return;
    this.picker.set('minDate', minDate ?? null);
    this.picker.set('maxDate', maxDate ?? null);
}

// Clear the value if it now falls outside [minDate, maxDate]. Returns true if cleared.
clearIfOutsideRange() {
    if (!this.picker) return false;
    const current = this.picker.selectedDates[0];
    if (!current) return false;
    const { minDate, maxDate } = this.picker.config;
    if ((minDate && current < minDate) || (maxDate && current > maxDate)) {
        this.picker.clear();
        this.element.dispatchEvent(new Event('blur')); // re-run live validateField where wired
        return true;
    }
    return false;
}

// Enable/disable interaction; clears the value when disabling so a disabled
// field can never retain a value that escapes the start+7 rule.
setEnabled(enabled) {
    if (!this.picker) return;
    this.picker.set('clickOpens', enabled);
    const visible = this.picker.altInput ?? this.element;
    visible.disabled = !enabled;
    visible.classList.toggle('opacity-50', !enabled);
    visible.classList.toggle('cursor-not-allowed', !enabled);
    if (!enabled && this.picker.selectedDates.length) {
        this.picker.clear();
        this.element.dispatchEvent(new Event('blur'));
    }
}
```
Only the **visible** input is disabled — the value-bearing original keeps syncing with Live UX. `null` max means "no upper bound" (onboarding); `clearIfOutsideRange` skips the max check when `maxDate` is null.

### 2. Drive the constraints from the duration-preset controller

File: `assets/controllers/duration_preset_controller.js`.

- Add Stimulus values (numbers come from the template, not JS constants). `maxMonths: 0` means **no upper bound**:
  ```js
  static values = {
      minGapDays: { type: Number, default: 7 },
      maxMonths:  { type: Number, default: 0 },
  };
  ```
- Add `syncEndConstraints()` and call it right after `syncEnabledState()` in both `connect()` and the document `input`/`change` handler:
  ```js
  syncEndConstraints() {
      const endInput = this.endInput();
      if (!endInput) return;
      const endCtrl = this.application.getControllerForElementAndIdentifier(endInput, 'datepicker');
      if (!endCtrl || !endCtrl.picker) return; // brief Live-morph window — guard like apply()

      const start = parseIsoDate(this.startInput()?.value ?? '');
      if (!start) {
          endCtrl.setRange(null, null);
          endCtrl.setEnabled(false);  // disabled + cleared until a start exists
          return;
      }
      endCtrl.setEnabled(true);
      const min = addDaysSafe(start, this.minGapDaysValue);                       // start + 7 days
      const max = this.maxMonthsValue > 0 ? addMonthsSafe(start, this.maxMonthsValue) : null; // +1y or none
      endCtrl.setRange(min, max);
      endCtrl.clearIfOutsideRange();  // drop an already-picked, now-invalid end date
  }
  ```
  Add an `addDaysSafe(date, days)` helper next to the existing `addMonthsSafe`. Leave the existing button-enable + hint logic in `syncEnabledState()` untouched (it no-ops when there are no button/hint targets).

### 3. Order form — pass the rule numbers

File: `templates/components/OrderForm.html.twig:252`. Add to the existing wrapper:
```twig
<div data-controller="duration-preset"
     data-duration-preset-min-gap-days-value="7"
     data-duration-preset-max-months-value="12">
```

### 4. Onboarding form — wrap the end field for constraints (no preset buttons)

File: `templates/components/AdminOnboardingForm.html.twig`, the "Datum" card (≈ lines 99-112). Wrap **only the end field** (LIMITED branch) so the same controller manages it; no buttons/hint needed:
```twig
<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    {{ form_row(form.startDate) }}
    {% if rentalIsLimited %}
        <div data-controller="duration-preset"
             data-duration-preset-min-gap-days-value="7"
             data-duration-preset-max-months-value="0">
            {{ form_row(form.endDate) }}
        </div>
    {% else %}
        {% do form.endDate.setRendered %}
    {% endif %}
</div>
```
`max-months-value="0"` ⇒ no upper bound (admin may set fixed terms longer than a year). The start field keeps its backdating freedom (no `minDate` added). The constraint clearing dispatches a `blur` the onboarding end field doesn't bind to — a harmless no-op; the min/max/disable/clear behavior is pure Flatpickr and works without live validation.

## Acceptance

**Order form:**
- [ ] No start chosen → end field visibly disabled (greyed, non-clickable), "Nejdříve zvolte datum začátku" hint shown, quick-buttons disabled as today.
- [ ] Pick start → end picker enabled and limited to **[start+7d, start+1y]**, instantly (no round-trip wait).
- [ ] Pick end, then change start so end is now <7d away or >1y → end date **cleared** and re-constrained.
- [ ] Clear start → end picker disabled again and its value cleared.
- [ ] 1/3/6-month quick-buttons still work and land in-range (no `duration-preset#apply` regression).

**Onboarding form:**
- [ ] No start chosen → end field disabled.
- [ ] Pick start (incl. a **past** date — backdating still allowed) → end picker enabled with min = **start+7d** and **no upper limit**.
- [ ] Changing start to leave end <7d away → end **cleared**.
- [ ] No preset buttons appear (none rendered); no console errors from the absent button/hint targets.

**Both:**
- [ ] Server-side `validateDates` still rejects an out-of-range end typed manually (client is a convenience layer).
- [ ] Works across Live morphs (controllers reconnect → constraints re-applied from current start value).
- [ ] `composer quality` is green (JS + template attrs; no PHP logic change).

## Out of scope

- **Adding the 1/3/6-month quick-buttons to the onboarding form.** Possible later (the controller supports it), but not requested — onboarding gets constraints only.
- **Changing the 7-day rule, the order form's 1-year cap, or backdating policy.** Numbers/policy mirrored from the two `validateDates` methods as-is.
- **Adding live per-field validation to the onboarding date fields.** Not needed for the picker constraints; a separate concern.
- **Removing the order form's server-side `PRE_SET_DATA` min re-application.** Stays as authoritative fallback under the client link.
- **The order form's ">1 year → zvolte dobu neurčitou" messaging flow.** Capping the picker at +1y makes it mainly reachable via manual typing; not redesigned here.

## Open questions

None — proceed.
