import { Controller } from '@hotwired/stimulus';

/*
 * Motif « disclosure » (menus déroulants, méga-menu) : bouton aria-expanded + panneau.
 * Échap ferme et rend le focus au bouton ; clic extérieur ou sortie du focus ferme ; un seul panneau ouvert à la fois.
 */
export default class extends Controller {
    static targets = ['button', 'panel'];

    connect() {
        this.onDocumentClick = (event) => {
            if (!this.element.contains(event.target)) {
                this.close();
            }
        };
        this.onKeydown = (event) => {
            if (event.key === 'Escape' && this.isOpen()) {
                this.close();
                this.buttonTarget.focus();
            }
        };
        this.onOtherOpened = (event) => {
            if (event.detail !== this) {
                this.close();
            }
        };
        document.addEventListener('click', this.onDocumentClick);
        document.addEventListener('keydown', this.onKeydown);
        document.addEventListener('tm:disclosure-opened', this.onOtherOpened);
        this.element.addEventListener('focusout', (event) => {
            if (event.relatedTarget && !this.element.contains(event.relatedTarget)) {
                this.close();
            }
        });
    }

    disconnect() {
        document.removeEventListener('click', this.onDocumentClick);
        document.removeEventListener('keydown', this.onKeydown);
        document.removeEventListener('tm:disclosure-opened', this.onOtherOpened);
    }

    isOpen() {
        return this.buttonTarget.getAttribute('aria-expanded') === 'true';
    }

    toggle(event) {
        event.preventDefault();
        this.isOpen() ? this.close() : this.open();
    }

    open() {
        this.buttonTarget.setAttribute('aria-expanded', 'true');
        this.panelTarget.hidden = false;
        document.dispatchEvent(new CustomEvent('tm:disclosure-opened', { detail: this }));
    }

    close() {
        this.buttonTarget.setAttribute('aria-expanded', 'false');
        this.panelTarget.hidden = true;
    }
}
