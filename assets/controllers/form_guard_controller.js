import { Controller } from '@hotwired/stimulus';

/*
 * Submit guard for plain (non-Live) forms. Two jobs:
 *
 *  1. Never silently block: on submit, if a required control is empty/unchecked
 *     it cancels the submit, reveals the summary line, smooth-scrolls to the
 *     first offending control (accounting for the sticky navbar) and flashes it.
 *  2. Double-submit protection: once a valid submit goes through, the form and
 *     button lock (disabled + spinner + "Odesílám…") and a re-entry flag drops
 *     any second submit.
 *
 * It reads real DOM values, not Alpine state, so it stays correct regardless of
 * how the page wires its widgets. Required controls and the submit button can
 * live anywhere inside the controller element — on the order/sign pages the
 * controller sits on the wrapper that contains both the visible widgets and the
 * <form>, and the form's bubbling submit event is what we intercept.
 *
 * Markup contract:
 *   data-controller="form-guard"
 *   targets: required, summary, submit, spinner, label
 *   on a required control, optional: data-form-guard-anchor="<css selector>"
 *     points at the visible section to scroll to / flash (used when the control
 *     itself is a hidden input, e.g. the signature data field).
 */
export default class extends Controller {
    static targets = ['required', 'summary', 'submit', 'spinner', 'label'];
    static values = {
        offset: { type: Number, default: 96 },
        submittingText: { type: String, default: 'Odesílám…' },
    };

    connect() {
        this.submitting = false;
        this.onSubmit = this.onSubmit.bind(this);
        // Capture phase so we run before the browser performs the submit.
        this.element.addEventListener('submit', this.onSubmit, true);
    }

    disconnect() {
        this.element.removeEventListener('submit', this.onSubmit, true);
    }

    onSubmit(event) {
        if (this.submitting) {
            event.preventDefault();
            event.stopImmediatePropagation();

            return;
        }

        const firstInvalid = this.requiredTargets.find(
            (el) => this.isActive(el) && !this.isFilled(el),
        );

        if (firstInvalid) {
            event.preventDefault();
            event.stopImmediatePropagation();
            this.revealSummary();
            this.focusAndScroll(firstInvalid);

            return;
        }

        // Valid — lock down and let the native submit proceed.
        this.lock();
    }

    isFilled(el) {
        if (el.type === 'checkbox' || el.type === 'radio') {
            return el.checked;
        }

        return String(el.value ?? '').trim() !== '';
    }

    // A requirement is "active" only when the user can actually act on it — a
    // section hidden by a conditional branch must never block the submit.
    isActive(el) {
        const anchor = this.anchorFor(el);

        return anchor.offsetParent !== null && anchor.getClientRects().length > 0;
    }

    anchorFor(el) {
        const selector = el.dataset.formGuardAnchor;
        if (selector) {
            return document.querySelector(selector) ?? el;
        }

        return el;
    }

    focusAndScroll(el) {
        const anchor = this.anchorFor(el);
        const top = anchor.getBoundingClientRect().top + window.scrollY - this.offsetValue;
        window.scrollTo({ top: Math.max(top, 0), behavior: 'smooth' });

        anchor.classList.add('form-guard-flash');
        window.setTimeout(() => anchor.classList.remove('form-guard-flash'), 1600);

        const focusable = el.matches('input, select, textarea')
            ? el
            : anchor.querySelector('input:not([type=hidden]), select, textarea');
        focusable?.focus?.({ preventScroll: true });
    }

    revealSummary() {
        if (this.hasSummaryTarget) {
            this.summaryTarget.classList.remove('hidden');
        }
    }

    lock() {
        this.submitting = true;

        if (this.hasSubmitTarget) {
            this.submitTarget.disabled = true;
        }
        if (this.hasSpinnerTarget) {
            this.spinnerTarget.classList.remove('hidden');
        }
        if (this.hasLabelTarget) {
            this.labelTarget.textContent = this.submittingTextValue;
        }
    }
}
