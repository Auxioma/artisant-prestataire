import { Controller } from '@hotwired/stimulus';

/* Compteur de caractères d'un champ limité (annoncé aux lecteurs d'écran à l'approche de la limite). */
export default class extends Controller {
    static targets = ['input', 'count'];
    static values = { max: Number };

    update() {
        const length = this.inputTarget.value.length;
        const format = new Intl.NumberFormat('fr-FR');
        this.countTarget.textContent = `${format.format(length)} / ${format.format(this.maxValue)}`;
        this.countTarget.setAttribute('aria-live', length > this.maxValue - 20 ? 'polite' : 'off');
    }
}
