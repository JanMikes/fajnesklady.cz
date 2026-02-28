import { Controller } from '@hotwired/stimulus';
import SignaturePad from 'signature_pad';

const FONT_STYLES = [
    { id: 'dancing-script', family: 'Dancing Script' },
    { id: 'great-vibes', family: 'Great Vibes' },
    { id: 'satisfy', family: 'Satisfy' },
    { id: 'pacifico', family: 'Pacifico' },
];

export default class extends Controller {
    static targets = [
        'drawCanvas', 'typedCanvas', 'typedInput',
        'preview', 'previewImage',
        'dataField', 'methodField', 'typedNameField', 'styleIdField',
        'drawTab', 'typedTab', 'drawPanel', 'typedPanel',
        'confirmBtn',
    ];

    static values = {
        mode: { type: String, default: 'draw' },
        styleId: { type: String, default: 'dancing-script' },
    };

    connect() {
        this.signaturePad = null;
        this._initDrawCanvas();
    }

    disconnect() {
        if (this.signaturePad) {
            this.signaturePad.off();
            this.signaturePad = null;
        }
    }

    switchToDraw() {
        this.modeValue = 'draw';
        this.drawPanelTarget.classList.remove('hidden');
        this.typedPanelTarget.classList.add('hidden');
        this.drawTabTarget.classList.add('border-accent', 'text-accent');
        this.drawTabTarget.classList.remove('border-transparent', 'text-gray-500');
        this.typedTabTarget.classList.add('border-transparent', 'text-gray-500');
        this.typedTabTarget.classList.remove('border-accent', 'text-accent');

        this._initDrawCanvas();
    }

    switchToTyped() {
        this.modeValue = 'typed';
        this.typedPanelTarget.classList.remove('hidden');
        this.drawPanelTarget.classList.add('hidden');
        this.typedTabTarget.classList.add('border-accent', 'text-accent');
        this.typedTabTarget.classList.remove('border-transparent', 'text-gray-500');
        this.drawTabTarget.classList.add('border-transparent', 'text-gray-500');
        this.drawTabTarget.classList.remove('border-accent', 'text-accent');

        this._renderTypedSignature();
    }

    selectStyle(event) {
        this.styleIdValue = event.currentTarget.dataset.styleId;

        // Update active style button
        this.element.querySelectorAll('[data-style-btn]').forEach(btn => {
            btn.classList.toggle('ring-2', btn.dataset.styleId === this.styleIdValue);
            btn.classList.toggle('ring-accent', btn.dataset.styleId === this.styleIdValue);
        });

        this._renderTypedSignature();
    }

    onTypedInput() {
        this._renderTypedSignature();
    }

    clear() {
        if (this.modeValue === 'draw' && this.signaturePad) {
            this.signaturePad.clear();
        } else if (this.modeValue === 'typed') {
            this.typedInputTarget.value = '';
            this._renderTypedSignature();
        }
    }

    confirm() {
        let dataUrl;

        if (this.modeValue === 'draw') {
            if (!this.signaturePad || this.signaturePad.isEmpty()) {
                return;
            }
            dataUrl = this.signaturePad.toDataURL('image/png');
        } else {
            const canvas = this.typedCanvasTarget;
            const ctx = canvas.getContext('2d');
            const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
            const hasContent = imageData.data.some((val, idx) => idx % 4 === 3 && val > 0);
            if (!hasContent) {
                return;
            }
            dataUrl = canvas.toDataURL('image/png');
        }

        // Set hidden fields
        this.dataFieldTarget.value = dataUrl;
        this.methodFieldTarget.value = this.modeValue;
        this.typedNameFieldTarget.value = this.modeValue === 'typed' ? this.typedInputTarget.value : '';
        this.styleIdFieldTarget.value = this.modeValue === 'typed' ? this.styleIdValue : '';

        // Show preview
        this.previewImageTarget.src = dataUrl;
        this.previewTarget.classList.remove('hidden');

        // Dispatch event for Alpine.js
        this.element.dispatchEvent(new CustomEvent('signature:signed', { bubbles: true }));
    }

    _initDrawCanvas() {
        if (!this.hasDrawCanvasTarget) return;

        const canvas = this.drawCanvasTarget;
        const ratio = Math.max(window.devicePixelRatio || 1, 1);

        // Set display size from CSS
        const rect = canvas.getBoundingClientRect();
        if (rect.width > 0) {
            canvas.width = rect.width * ratio;
            canvas.height = rect.height * ratio;
            canvas.getContext('2d').scale(ratio, ratio);
        }

        if (this.signaturePad) {
            this.signaturePad.off();
        }

        this.signaturePad = new SignaturePad(canvas, {
            backgroundColor: 'rgb(255, 255, 255)',
            penColor: 'rgb(0, 0, 0)',
        });
    }

    _renderTypedSignature() {
        if (!this.hasTypedCanvasTarget || !this.hasTypedInputTarget) return;

        const canvas = this.typedCanvasTarget;
        const ctx = canvas.getContext('2d');
        const text = this.typedInputTarget.value.trim();
        const style = FONT_STYLES.find(s => s.id === this.styleIdValue) || FONT_STYLES[0];

        // Set canvas size
        const ratio = Math.max(window.devicePixelRatio || 1, 1);
        const rect = canvas.getBoundingClientRect();
        if (rect.width > 0) {
            canvas.width = rect.width * ratio;
            canvas.height = rect.height * ratio;
            ctx.scale(ratio, ratio);
        }

        // Clear
        ctx.fillStyle = 'white';
        ctx.fillRect(0, 0, rect.width, rect.height);

        if (!text) return;

        // Render text after fonts are ready
        document.fonts.ready.then(() => {
            ctx.fillStyle = 'white';
            ctx.fillRect(0, 0, rect.width, rect.height);

            const fontSize = Math.min(48, rect.width / (text.length * 0.5));
            ctx.font = `${fontSize}px "${style.family}", cursive`;
            ctx.fillStyle = 'black';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(text, rect.width / 2, rect.height / 2);
        });
    }
}
