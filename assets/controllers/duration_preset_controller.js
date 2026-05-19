import { Controller } from '@hotwired/stimulus';

const PRESETS = [1, 3, 6];

export default class extends Controller {
    static targets = ['button', 'hint'];

    connect() {
        this.boundSync = () => this.syncEnabledState();
        // The startDate flatpickr's hidden input is outside this controller's root
        // (it sits in its own form_row). Listen at document level so we catch
        // every value change, including programmatic setDate() calls.
        // Capture phase: Live UX's own listeners can call stopPropagation() during
        // a morph; capture ensures we still see the event.
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
