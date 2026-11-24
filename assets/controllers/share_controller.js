/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { track } from '../lib/analytics.js';

/* Partage d'une fiche (P10) ou d'un article (P15.2) : partage natif s'il existe, sinon copie du lien dans le presse-papiers. */
export default class extends Controller {
    static values = { url: String, title: String, event: { type: String, default: 'pro_profile_share' } };
    static targets = ['status'];

    async share() {
        try {
            if (navigator.share) {
                await navigator.share({ title: this.titleValue, url: this.urlValue });
            } else {
                await navigator.clipboard.writeText(this.urlValue);
                this.statusTarget.textContent = 'Lien copié dans le presse-papiers.';
            }
            track(this.eventValue);
        } catch (error) {
            if (error.name !== 'AbortError') {
                this.statusTarget.textContent = 'Copiez l’adresse de la page pour la partager.';
            }
        }
    }
}
