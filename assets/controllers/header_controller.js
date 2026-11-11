import { Controller } from '@hotwired/stimulus';

/* En-tête collant : réduit à 64 px avec ombre basse dès que la page défile (Figma 64:168). */
export default class extends Controller {
    connect() {
        this.ticking = false;
        this.onScroll = () => {
            if (!this.ticking) {
                this.ticking = true;
                requestAnimationFrame(() => {
                    this.element.classList.toggle('is-scrolled', window.scrollY > 8);
                    this.ticking = false;
                });
            }
        };
        window.addEventListener('scroll', this.onScroll, { passive: true });
        this.onScroll();
    }

    disconnect() {
        window.removeEventListener('scroll', this.onScroll);
    }
}
