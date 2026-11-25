import { Controller } from '@hotwired/stimulus';

/*
 * En-tête collant : réduit à 64 px avec ombre basse dès que la page défile (Figma 64:168).
 * Hystérésis : réduire l'en-tête (80 → 64 px) remonte la page de 16 px ; avec un seuil unique, le défilement repassait
 * sous le seuil, l'en-tête se ré-agrandissait, et ainsi de suite (en-tête qui « danse » près du haut de page).
 * L'écart entre les deux seuils (40 px / 8 px) dépasse ces 16 px : plus d'oscillation.
 */
const SHRINK_AT = 40;
const EXPAND_BELOW = 8;

export default class extends Controller {
    connect() {
        this.ticking = false;
        this.onScroll = () => {
            if (!this.ticking) {
                this.ticking = true;
                requestAnimationFrame(() => {
                    const scrolled = this.element.classList.contains('is-scrolled');
                    const y = window.scrollY;
                    if (!scrolled && y > SHRINK_AT) {
                        this.element.classList.add('is-scrolled');
                    } else if (scrolled && y < EXPAND_BELOW) {
                        this.element.classList.remove('is-scrolled');
                    }
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
