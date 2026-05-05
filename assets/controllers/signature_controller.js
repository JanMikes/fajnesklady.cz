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
        'drawCanvas', 'typedCanvas',
        'dataField', 'methodField', 'typedNameField', 'styleIdField',
    ];

    static values = {
        styleId: { type: String, default: 'dancing-script' },
        customerName: { type: String, default: '' },
    };

    connect() {
        this.signaturePad = null;
        // Both panels render in the same DOM pass; canvas sizes depend on
        // layout being settled, so defer one frame.
        requestAnimationFrame(() => {
            this._initDrawCanvas();
            this._renderTypedSignature();
        });
    }

    disconnect() {
        if (this.signaturePad) {
            this.signaturePad.off();
            this.signaturePad = null;
        }
    }

    // Radio change on a typed-style option: render that style and capture
    // immediately so the user doesn't need a separate "confirm" click.
    selectStyle(event) {
        this.styleIdValue = event.currentTarget.dataset.styleId;
        this._renderTypedSignature();
        this._captureSignature('typed');
    }

    // Radio change on the draw option: if the canvas already has strokes
    // (user came back to draw after wandering off), keep them and capture;
    // otherwise emit a cleared event so the submit button stays disabled
    // until the user actually draws something.
    selectDraw() {
        if (this.signaturePad && !this.signaturePad.isEmpty()) {
            this._captureSignature('draw');
        } else {
            this._clearHiddenFields();
        }
    }

    clear(event) {
        const mode = event.params?.mode ?? 'draw';
        if (mode === 'draw' && this.signaturePad) {
            this.signaturePad.clear();
            this._clearHiddenFields();
        }
    }

    _captureSignature(mode) {
        let dataUrl;

        if (mode === 'draw') {
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

        this.dataFieldTarget.value = dataUrl;
        this.methodFieldTarget.value = mode;
        this.typedNameFieldTarget.value = mode === 'typed' ? this.customerNameValue : '';
        this.styleIdFieldTarget.value = mode === 'typed' ? this.styleIdValue : '';

        this.element.dispatchEvent(new CustomEvent('signature:signed', { bubbles: true }));
    }

    _clearHiddenFields() {
        this.dataFieldTarget.value = '';
        this.methodFieldTarget.value = '';
        this.typedNameFieldTarget.value = '';
        this.styleIdFieldTarget.value = '';
        this.element.dispatchEvent(new CustomEvent('signature:cleared', { bubbles: true }));
    }

    _initDrawCanvas() {
        if (!this.hasDrawCanvasTarget) return;

        const canvas = this.drawCanvasTarget;
        const ratio = Math.max(window.devicePixelRatio || 1, 1);

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

        // Capture after each stroke so the user doesn't need a "confirm" click.
        this.signaturePad.addEventListener('endStroke', () => {
            this._captureSignature('draw');
        });
    }

    _renderTypedSignature() {
        if (!this.hasTypedCanvasTarget) return;

        const canvas = this.typedCanvasTarget;
        const ctx = canvas.getContext('2d');
        const text = this.customerNameValue.trim();
        const style = FONT_STYLES.find(s => s.id === this.styleIdValue) || FONT_STYLES[0];

        const ratio = Math.max(window.devicePixelRatio || 1, 1);
        const rect = canvas.getBoundingClientRect();
        if (rect.width > 0) {
            canvas.width = rect.width * ratio;
            canvas.height = rect.height * ratio;
            ctx.scale(ratio, ratio);
        }

        ctx.fillStyle = 'white';
        ctx.fillRect(0, 0, rect.width, rect.height);

        if (!text) return;

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
