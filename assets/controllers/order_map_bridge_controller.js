import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';

// The storage map now lives INSIDE the OrderForm Live Component. It still
// dispatches a bubbling 'storage-map:select' event on click; this controller
// (mounted on the component root) forwards it to the live `selectStorage`
// action. The map's highlight is re-rendered server-side from the storageId
// LiveProp, so no client-side highlight sync is needed here anymore.
export default class extends Controller {
    async selectStorage(event) {
        const storageId = event.detail?.storageId;
        if (!storageId) return;

        const component = await getComponent(this.element);
        await component.action('selectStorage', { storageId });
    }
}
