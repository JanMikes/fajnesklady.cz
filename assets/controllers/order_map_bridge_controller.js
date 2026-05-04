import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';

// Wires the storage map (which dispatches 'storage-map:select' on click) to the
// OrderForm Live Component (which exposes a 'selectStorage' action). The map
// stays outside the live component, so its highlight value is updated here too.
export default class extends Controller {
    static targets = ['liveForm', 'map'];

    async selectStorage(event) {
        const storageId = event.detail?.storageId;
        if (!storageId) return;

        const liveEl = this.liveElement();
        if (!liveEl) return;

        const component = await getComponent(liveEl);
        await component.action('selectStorage', { storageId });

        // Map is rendered outside the live component, so its highlight is not
        // re-rendered automatically — keep it in sync with the new selection.
        if (this.hasMapTarget) {
            this.mapTarget.setAttribute('data-storage-map-highlight-storage-value', storageId);
        }
    }

    // When the map element re-mounts (e.g., user toggled "pick from map" again),
    // pull the live component's current storageId so the highlight matches.
    mapTargetConnected(element) {
        const storageId = this.currentStorageId();
        if (storageId) {
            element.setAttribute('data-storage-map-highlight-storage-value', storageId);
        }
    }

    liveElement() {
        // The component renders its own root (<div {{ attributes }}>) inside the wrapping target.
        // getComponent() expects exactly that root element, so walk down to it.
        return this.liveFormTarget.querySelector('[data-live-name-value]');
    }

    currentStorageId() {
        const liveEl = this.liveElement();
        const propsAttr = liveEl?.getAttribute('data-live-props-value');
        if (!propsAttr) return null;
        try {
            const props = JSON.parse(propsAttr);
            return props.storageId || null;
        } catch {
            return null;
        }
    }
}
