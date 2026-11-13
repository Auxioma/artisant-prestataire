import { Controller } from '@hotwired/stimulus';

/* C03.4 — « Imprimer / PDF » : impression du comparatif (le navigateur propose l'enregistrement en PDF). */
export default class extends Controller {
    print() {
        window.print();
    }
}
