import { Controller } from '@hotwired/stimulus';

/*
 * TM / Modale : <dialog> natif ouvert par showModal() (focus piégé, Échap, arrière-plan inerte).
 * Retour du focus à l'élément déclencheur à la fermeture (CDC §3.3) ; clic sur le fond = fermeture.
 */
const openers = new WeakMap();

export function openDialog(dialog, opener) {
    openers.set(dialog, opener ?? document.activeElement);
    dialog.showModal();
}

export default class extends Controller {
    close() {
        this.element.close();
    }

    backdrop(event) {
        if (event.target === this.element) {
            this.element.close();
        }
    }

    restoreFocus() {
        const opener = openers.get(this.element);
        if (opener && typeof opener.focus === 'function' && document.contains(opener)) {
            opener.focus();
        }
    }
}
