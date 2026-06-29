import { Controller } from '@hotwired/stimulus';

/*
 * Reveals the "Jak to funguje?" process steps left-to-right when the flow
 * scrolls into view. Adds the `in-view` class once; CSS handles the staggered
 * transitions (and honours prefers-reduced-motion).
 */
export default class extends Controller {
    connect() {
        if (typeof IntersectionObserver === 'undefined') {
            this.element.classList.add('in-view');
            return;
        }

        this.observer = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    this.element.classList.add('in-view');
                    this.observer.disconnect();
                }
            });
        }, { threshold: 0.35 });

        this.observer.observe(this.element);
    }

    disconnect() {
        this.observer?.disconnect();
    }
}
