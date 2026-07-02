import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';
import flatpickr from 'flatpickr';
import 'flatpickr/dist/flatpickr.min.css';
import { Czech } from 'flatpickr/dist/l10n/cs.js';

export default class extends Controller {
    static values = {
        minDate: { type: String, default: '' },
        maxDate: { type: String, default: '' },
        defaultDate: { type: String, default: '' }
    }

    connect() {
        this.initializeDatepicker();
        this.hookIntoLiveComponent();
    }

    disconnect() {
        this.liveHookCancelled = true;
        this.liveComponent?.off('render:finished', this.boundResyncModel);
        if (this.picker) {
            this.picker.destroy();
        }
    }

    // The whole picker sits inside a data-live-ignore wrapper, so a Live morph can
    // never change what the user SEES — the input's DOM value is the single source
    // of truth. But the Live MODEL can still diverge from it (a commit whose
    // change event raced a morph/in-flight request), and then validation runs
    // against a stale/empty value: the field visibly holds "6. 6. 1992" while the
    // server insists it is required. Re-assert the DOM value into the model after
    // every morph; the triggered re-render then re-validates and clears the
    // phantom error. One re-dispatch per DOM value — if the server still disagrees
    // after a round-trip (it rejected the value), don't ping-pong.
    async hookIntoLiveComponent() {
        this.liveComponent = null;
        this.liveHookCancelled = false;
        this.lastResyncedValue = null;
        this.boundResyncModel = () => this.resyncModel();

        const liveRoot = this.element.closest('[data-controller~="live"]');
        if (!liveRoot) {
            return; // plain (non-live) form — no morphs, nothing to diverge
        }
        try {
            const component = await getComponent(liveRoot);
            if (this.liveHookCancelled) {
                return;
            }
            this.liveComponent = component;
            this.liveComponent.on('render:finished', this.boundResyncModel);
        } catch {
            this.liveComponent = null;
        }
    }

    resyncModel() {
        if (!this.liveComponent) {
            return;
        }
        const modelValue = this.readModelValue();
        const domValue = this.element.value;
        if (modelValue === null || modelValue === domValue) {
            this.lastResyncedValue = null;
            return;
        }
        if (this.lastResyncedValue === domValue) {
            return; // the server already refused this exact value once — don't ping-pong
        }
        this.lastResyncedValue = domValue;

        // name "form[field]" → model path "form.field"
        const model = (this.element.getAttribute('name') ?? '').replace(/\[([^\]]+)\]/g, '.$1');
        // Deferred out of the render:finished callback: a model update pushed
        // synchronously from inside the hook is swallowed by the response
        // processing that is still unwinding. component.set() (not DOM events)
        // marks the model dirty deterministically and re-renders.
        setTimeout(() => {
            if (this.element.value === domValue) {
                this.liveComponent.set(model, domValue, true);
            }
        }, 0);
    }

    // The server-side model value for this input, read from the live props the
    // morph just rendered (name "form[field]" → props.form.field).
    readModelValue() {
        const propsHolder = this.element.closest('[data-live-props-value]');
        const name = this.element.getAttribute('name') ?? '';
        const match = name.match(/^([^\[]+)\[([^\]]+)\]$/);
        if (!propsHolder || !match) {
            return null;
        }
        try {
            const props = JSON.parse(propsHolder.getAttribute('data-live-props-value'));
            const value = props[match[1]]?.[match[2]];
            return typeof value === 'string' ? value : null;
        } catch {
            return null;
        }
    }

    initializeDatepicker() {
        const config = {
            locale: Czech,
            dateFormat: 'Y-m-d',
            altInput: true,
            altFormat: 'j. n. Y',
            // Match the project's base input styling so .form-group:has(> .form-error)
            // CSS can apply error styling consistently across regular inputs and the
            // visible flatpickr alt-input.
            altInputClass: 'form-input',
            allowInput: true,
            disableMobile: true,
            // Close the calendar as soon as a day is chosen — picking a date is a
            // complete action here (no time component), so keeping it open reads as
            // unresponsive.
            closeOnSelect: true,
            // Lenient parser: accept "6.6.1992", "6. 6. 1992", "6/6/1992", "6-6-1992",
            // any spacing variant, plus the ISO Y-m-d that flatpickr itself uses for
            // minDate/maxDate/defaultDate and the hidden input value.
            parseDate: this.parseDate,
            // The hidden <input> is bound to the Live Component model via the form's
            // data-model="on(change)|*", so the picked value only reaches `formValues`
            // (and therefore server-side validation) on a `change` event. flatpickr
            // sets this.element.value BEFORE this hook runs, but only emits its OWN
            // native change AFTER every onChange hook — too late for any blur-wired
            // validateField that fires in between, which would then validate a STALE
            // (empty) value and surface a phantom "required/invalid" error.
            //
            // So drive the model ourselves here, in order:
            //   1. change/input  -> Live model updates synchronously (formValues fresh)
            //   2. blur          -> blur-wired validateField re-validates the fresh value
            onChange: () => {
                this.element.dispatchEvent(new Event('input', { bubbles: true }));
                this.element.dispatchEvent(new Event('change', { bubbles: true }));
                this.element.dispatchEvent(new Event('blur'));
            }
        };

        if (this.minDateValue) {
            config.minDate = this.minDateValue;
        }

        if (this.maxDateValue) {
            config.maxDate = this.maxDateValue;
        }

        if (this.defaultDateValue) {
            config.defaultDate = this.defaultDateValue;
        }

        this.picker = flatpickr(this.element, config);

        // altInput is the visible input the user types/blurs; this.element
        // is the hidden original that carries the form value and the
        // `blur->live#action` Stimulus binding. Without this forward, manual
        // typing followed by tabbing away never reaches Live UX — flatpickr
        // parses the typed text on alt-blur and updates the hidden input, but
        // the blur event itself stays on the alt input and the hidden one is
        // already off-screen so it never fires its own blur. The user sees a
        // stale "invalid date" until they re-open the picker and click. This
        // listener runs after flatpickr's own blur handler (registered first
        // during flatpickr() above), so the hidden input is up-to-date by the
        // time we dispatch.
        if (this.picker.altInput) {
            this.picker.altInput.addEventListener('blur', () => {
                this.element.dispatchEvent(new Event('blur'));
            });
        }
    }

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

    parseDate(dateString) {
        if (!dateString) {
            return undefined;
        }

        if (dateString instanceof Date) {
            return isNaN(dateString.getTime()) ? undefined : dateString;
        }

        const trimmed = String(dateString).trim();
        if (!trimmed) {
            return undefined;
        }

        // ISO Y-m-d — flatpickr uses this for the hidden input value and for
        // minDate/maxDate/defaultDate configured via data-* attributes.
        let m = trimmed.match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/);
        if (m) {
            return makeDate(Number(m[1]), Number(m[2]), Number(m[3]));
        }

        // Czech-style d.m.Y / d/m/Y / d-m-Y with any whitespace around separators.
        const compact = trimmed.replace(/\s+/g, '');

        m = compact.match(/^(\d{1,2})[./-](\d{1,2})[./-](\d{4})$/);
        if (m) {
            return makeDate(Number(m[3]), Number(m[2]), Number(m[1]));
        }

        // Two-digit year (e.g. 6.6.92): pivot at 30 → 2030 vs 1930.
        m = compact.match(/^(\d{1,2})[./-](\d{1,2})[./-](\d{2})$/);
        if (m) {
            const yy = Number(m[3]);
            const year = yy < 30 ? 2000 + yy : 1900 + yy;
            return makeDate(year, Number(m[2]), Number(m[1]));
        }

        return undefined;
    }
}

function makeDate(year, month, day) {
    const d = new Date(year, month - 1, day);
    if (isNaN(d.getTime())) {
        return undefined;
    }
    // Reject impossible dates that JS silently rolls over (e.g. 31.2. → 3.3.).
    if (d.getFullYear() !== year || d.getMonth() !== month - 1 || d.getDate() !== day) {
        return undefined;
    }
    return d;
}
