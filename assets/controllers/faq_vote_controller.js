import { Controller } from '@hotwired/stimulus';
import { track } from '../lib/analytics.js';

/* P14.3 — vote « Cette réponse vous a-t-elle aidé ? » sans rechargement ; un « non » propose le contact pré-rempli. */
export default class extends Controller {
    static targets = ['form', 'status'];

    async submit(event) {
        event.preventDefault();
        const helpful = event.submitter?.value ?? '1';
        const body = new FormData(this.formTarget);
        body.set('helpful', helpful);
        try {
            const response = await fetch(this.formTarget.action, { method: 'POST', body, headers: { Accept: 'application/json' } });
            if (!response.ok) {
                throw new Error();
            }
            const { contact } = await response.json();
            this.statusTarget.textContent = 'Merci pour votre retour !';
            if (contact) {
                const link = document.createElement('a');
                link.href = contact;
                link.textContent = 'Posez-nous votre question';
                this.statusTarget.append(' ', link, ' : nous vous répondons par e-mail.');
            }
            this.formTarget.hidden = true;
            this.statusTarget.hidden = false;
            track('faq_feedback', { type: helpful === '1' ? 'yes' : 'no' });
        } catch {
            this.statusTarget.textContent = 'Votre vote n’a pas pu être enregistré. Réessayez plus tard.';
            this.statusTarget.hidden = false;
        }
    }
}
