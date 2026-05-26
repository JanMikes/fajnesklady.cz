import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';

export default class extends Controller {
    async handleStorageSelect(event) {
        const storageId = event.detail?.storageId;
        if (!storageId) return;

        const component = await getComponent(this.element);
        await component.action('selectStorage', { storageId });
    }
}
