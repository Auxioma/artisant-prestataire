import { Controller } from '@hotwired/stimulus';

/* P02.3 mobile : panneau de filtres replié par défaut (bouton « Filtres (n) ») ; toujours ouvert sur grand écran. */
export default class extends Controller {
    connect() {
        this.desktop = window.matchMedia('(min-width: 1024px)');
        this.onChange = () => { this.element.open = this.desktop.matches; };
        this.desktop.addEventListener('change', this.onChange);
        this.onChange();
    }

    disconnect() {
        this.desktop.removeEventListener('change', this.onChange);
    }
}
