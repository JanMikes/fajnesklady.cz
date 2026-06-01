import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';

/*
 * Scroll-to-first-error for Symfony UX Live Component forms.
 *
 * Live Components validate server-side and re-render (morph) the DOM on every
 * interaction. We only want to scroll on an actual submit attempt — not on a
 * per-field blur validation. The submit button calls markSubmit() (wired
 * alongside the live action), which arms a flag; the component's next
 * `render:finished` hook then scrolls to the first visible error and clears it.
 *
 * Double-submit protection is handled declaratively in the template via the
 * Live `data-loading="action(submit)|…"` directive (disable + spinner) — this
 * controller only owns the scroll.
 *
 * Markup contract:
 *   data-controller="live-form-scroll"   (on the component root)
 *   submit button: data-action="live#action live-form-scroll#markSubmit"
 */
export default class extends Controller {
    static values = { offset: { type: Number, default: 96 } };

    async connect() {
        this.expecting = false;
        this.cancelled = false;
        this.onRender = this.onRender.bind(this);
        try {
            const component = await getComponent(this.element);
            // disconnect() may have fired while getComponent was still resolving.
            if (this.cancelled) {
                return;
            }
            this.component = component;
            this.component.on('render:finished', this.onRender);
        } catch {
            // Not a mounted Live Component — nothing to hook into.
            this.component = null;
        }
    }

    disconnect() {
        this.cancelled = true;
        this.component?.off('render:finished', this.onRender);
    }

    markSubmit() {
        this.expecting = true;
    }

    onRender() {
        if (!this.expecting) {
            return;
        }
        this.expecting = false;

        const first = [...this.element.querySelectorAll('.form-error, .form-input-error, [data-live-error]')]
            .find((el) => el.offsetParent !== null && el.getClientRects().length > 0);

        // No visible error → the submit succeeded (it will have redirected).
        if (!first) {
            return;
        }

        const anchor = first.closest('.form-group, [data-form-error-section]') ?? first;
        const top = anchor.getBoundingClientRect().top + window.scrollY - this.offsetValue;
        window.scrollTo({ top: Math.max(top, 0), behavior: 'smooth' });

        const focusable = anchor.querySelector('input:not([type=hidden]), select, textarea');
        focusable?.focus?.({ preventScroll: true });
    }
}
