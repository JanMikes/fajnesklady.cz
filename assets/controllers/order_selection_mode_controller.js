import { Controller } from '@hotwired/stimulus';

// Toggles the storage map's visibility based on a pair of radio buttons
// ("auto" vs. "pick from map"). When "manual" is selected, scrolls the page
// to the map for an obvious next step. The radios also carry
// `data-model="on(change)|selectionMode"` so Live UX itself syncs the value
// to the OrderForm Live Component's `selectionMode` LiveProp; this controller
// is only responsible for client-side UI (map visibility + scroll).
export default class extends Controller {
    static targets = ['map'];
    static values = { mode: { type: String, default: 'auto' } };

    switch(event) {
        const newMode = event.target.value;
        this.modeValue = newMode;
        if (newMode === 'manual') {
            this.scrollToMap();
        }
    }

    modeValueChanged() {
        if (!this.hasMapTarget) return;
        this.mapTarget.classList.toggle('hidden', this.modeValue !== 'manual');
    }

    scrollToMap() {
        if (!this.hasMapTarget) return;
        // Wait for the next frame so the element has its final layout after the .hidden toggle.
        requestAnimationFrame(() => {
            const offset = 96; // matches sidebar's `sticky top-24` clearance for the fixed navbar
            const top = this.mapTarget.getBoundingClientRect().top + window.scrollY - offset;
            window.scrollTo({ top, behavior: 'smooth' });
        });
    }
}
