import { Controller } from '@hotwired/stimulus';

/*
 * Colonnes du pied de page : toujours ouvertes sur desktop, repliées par défaut sous 1024 px.
 * Sans JavaScript, elles restent ouvertes (attribut open dans le HTML).
 */
const desktop = window.matchMedia('(min-width: 1024px)');

export default class extends Controller {
    connect() {
        this.sync = () => { this.element.open = desktop.matches; };
        this.sync();
        desktop.addEventListener('change', this.sync);
        this.element.querySelector('summary')?.addEventListener('click', (event) => {
            if (desktop.matches) {
                event.preventDefault();
            }
        });
    }

    disconnect() {
        desktop.removeEventListener('change', this.sync);
    }
}
