import { Controller } from '@hotwired/stimulus';

/* Compteur de caractères d'un champ limité (annoncé aux lecteurs d'écran à l'approche de la limite). */
export default class extends Controller {
    static targets = ['input', 'count'];
    static values = { max: Number };

    update() {
        const length = this.inputTarget.value.length;
        this.countTarget.textContent = `${length} / ${this.maxValue}`;
        this.countTarget.setAttribute('aria-live', length > this.maxValue - 20 ? 'polite' : 'off');
    }
}
