import { Controller } from '@hotwired/stimulus';

/*
 * Navigation du back-office : ouverte par défaut (sans JavaScript, tout reste accessible) ; repliée au chargement sur
 * petit écran, dépliée par le bouton « Menu » (aria-expanded). Toujours visible à partir de 1024 px, y compris quand
 * la fenêtre s'élargit après le chargement.
 */
export default class extends Controller {
    static targets = ['button', 'panel'];

    connect() {
        this.button.hidden = false;
        this.desktop = window.matchMedia('(min-width: 1024px)');
        this.onChange = () => this.set(this.desktop.matches);
        this.desktop.addEventListener('change', this.onChange);
        this.set(this.desktop.matches);
    }

    disconnect() {
        this.desktop.removeEventListener('change', this.onChange);
    }

    get button() {
        return this.buttonTarget;
    }

    toggle() {
        this.set(this.panelTarget.hidden);
    }

    set(open) {
        this.panelTarget.hidden = !open;
        this.button.setAttribute('aria-expanded', String(open));
    }
}
