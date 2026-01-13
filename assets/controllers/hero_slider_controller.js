import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['slide', 'dot'];
    static values = {
        interval: { type: Number, default: 5000 },
        autoplay: { type: Boolean, default: true }
    };

    connect() {
        this.currentIndex = 0;
        this.slides = this.slideTargets;
        this.dots = this.dotTargets;

        if (this.slides.length === 0) return;

        // Show first slide
        this.showSlide(0);

        // Start autoplay if enabled
        if (this.autoplayValue) {
            this.startAutoplay();
        }

        // Pause on hover
        this.element.addEventListener('mouseenter', () => this.pauseAutoplay());
        this.element.addEventListener('mouseleave', () => this.resumeAutoplay());
    }

    disconnect() {
        this.stopAutoplay();
    }

    showSlide(index) {
        // Wrap around
        if (index < 0) index = this.slides.length - 1;
        if (index >= this.slides.length) index = 0;

        // Hide all slides
        this.slides.forEach((slide, i) => {
            slide.classList.toggle('active', i === index);
        });

        // Update dots
        this.dots.forEach((dot, i) => {
            dot.classList.toggle('active', i === index);
        });

        this.currentIndex = index;
    }

    next() {
        this.showSlide(this.currentIndex + 1);
    }

    previous() {
        this.showSlide(this.currentIndex - 1);
    }

    goToSlide(event) {
        const index = parseInt(event.currentTarget.dataset.index, 10);
        this.showSlide(index);
        // Reset autoplay timer when manually navigating
        if (this.autoplayValue && this.intervalId) {
            this.stopAutoplay();
            this.startAutoplay();
        }
    }

    startAutoplay() {
        this.intervalId = setInterval(() => this.next(), this.intervalValue);
    }

    stopAutoplay() {
        if (this.intervalId) {
            clearInterval(this.intervalId);
            this.intervalId = null;
        }
    }

    pauseAutoplay() {
        this.wasPaused = !this.intervalId;
        this.stopAutoplay();
    }

    resumeAutoplay() {
        if (!this.wasPaused && this.autoplayValue) {
            this.startAutoplay();
        }
    }
}
