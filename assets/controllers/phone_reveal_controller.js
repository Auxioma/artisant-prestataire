/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { track } from '../lib/analytics.js';

/*
 * RG-030 — téléphone affiché au clic (P10). Sans JavaScript, le formulaire POST réaffiche la fiche avec le numéro.
 * Avec : requête JSON (même origine, jeton sans état vérifié par l'en-tête Origin), numéro rendu cliquable (tel:).
 */
export default class extends Controller {
    static targets = ['form', 'output'];

    async reveal(event) {
        event.preventDefault();
        const button = this.formTarget.querySelector('button');
        button.disabled = true;
        try {
            const response = await fetch(this.formTarget.action, {
                method: 'POST',
                body: new FormData(this.formTarget),
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!response.ok) {
                throw new Error(response.status === 429 ? 'limit' : 'error');
            }
            const { phone, href } = await response.json();
            const link = document.createElement('a');
            link.className = 'tm-button tm-button--secondary';
            link.href = href;
            link.textContent = phone;
            this.outputTarget.replaceChildren(link);
            this.outputTarget.hidden = false;
            this.formTarget.hidden = true;
            link.focus();
            track('pro_phone_reveal');
        } catch (error) {
            this.outputTarget.textContent = error.message === 'limit'
                ? 'Trop de numéros affichés aujourd’hui : réessayez demain.'
                : 'Le numéro n’a pas pu être affiché. Réessayez.';
            this.outputTarget.hidden = false;
            button.disabled = false;
        }
    }
}
