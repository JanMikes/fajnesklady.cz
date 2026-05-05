import './stimulus_bootstrap.js';
import Alpine from 'alpinejs';
import GLightbox from 'glightbox';
import 'glightbox/dist/css/glightbox.min.css';

Alpine.start();

// Page-wide GLightbox. One instance handles every `.glightbox` anchor on the
// page; `data-gallery="..."` per anchor groups photos for next/prev navigation.
//
// GLightbox binds individual click handlers at init time and never re-scans on
// its own. Whenever a `.glightbox` anchor is added or removed (Live UX morph,
// JS-driven innerHTML, etc.), we rebuild — using a MutationObserver because
// Symfony Live doesn't emit a global "render finished" DOM event we could
// hook into.
let lightbox = null;

function initLightbox() {
    if (lightbox) {
        lightbox.destroy();
    }
    lightbox = GLightbox({
        selector: '.glightbox',
        touchNavigation: true,
        loop: true,
    });
}

let reinitScheduled = false;
function scheduleReinit() {
    if (reinitScheduled) return;
    reinitScheduled = true;
    requestAnimationFrame(() => {
        reinitScheduled = false;
        initLightbox();
    });
}

function nodeHasGlightbox(node) {
    if (node.nodeType !== Node.ELEMENT_NODE) return false;
    return node.matches?.('.glightbox') || node.querySelector?.('.glightbox') !== null;
}

function start() {
    initLightbox();

    const observer = new MutationObserver((mutations) => {
        for (const mutation of mutations) {
            if (mutation.type !== 'childList') continue;
            for (const node of mutation.addedNodes) {
                if (nodeHasGlightbox(node)) {
                    scheduleReinit();
                    return;
                }
            }
            for (const node of mutation.removedNodes) {
                if (nodeHasGlightbox(node)) {
                    scheduleReinit();
                    return;
                }
            }
        }
    });
    observer.observe(document.body, { childList: true, subtree: true });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start, { once: true });
} else {
    start();
}
