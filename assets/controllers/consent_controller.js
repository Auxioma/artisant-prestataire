import { Controller } from '@hotwired/stimulus';
import { CATEGORIES, readConsent, writeConsent } from '../lib/consent.js';

/*
 * CMP (CDC §3.2) : affiche le bandeau sans choix valide ; enregistre le choix (cookie + preuve serveur) ;
 * n'active les scripts <script type="text/plain" data-consent-category="…"> qu'après consentement ;
 * rouvrable depuis tout élément [data-cookie-consent-open] (« Gérer mes cookies »).
 */
export default class extends Controller {
    static targets = ['banner', 'dialog', 'switch'];
    static values = { version: Number, months: Number, endpoint: String };

    connect() {
        const stored = readConsent(this.versionValue, this.monthsValue);
        if (stored) {
            this.apply(stored);
        } else {
            window.tmConsent = Object.fromEntries(CATEGORIES.map((k) => [k, false]));
            this.bannerTarget.hidden = false;
        }
        this.onOpenRequest = (event) => {
            const trigger = event.target.closest('[data-cookie-consent-open]');
            if (trigger) {
                event.preventDefault();
                this.openPreferences(event, trigger);
            }
        };
        document.addEventListener('click', this.onOpenRequest);
    }

    disconnect() {
        document.removeEventListener('click', this.onOpenRequest);
    }

    acceptAll(event) {
        event?.preventDefault();
        this.persist(Object.fromEntries(CATEGORIES.map((k) => [k, true])), 'accept_all');
    }

    refuseAll(event) {
        event?.preventDefault();
        this.persist(Object.fromEntries(CATEGORIES.map((k) => [k, false])), 'refuse_all');
    }

    save(event) {
        event.preventDefault();
        const choices = Object.fromEntries(CATEGORIES.map((k) => [k, this.input(k)?.checked === true]));
        this.persist(choices, 'custom');
    }

    openPreferences(event, trigger = null) {
        event?.preventDefault();
        this.opener = trigger ?? event?.currentTarget ?? document.activeElement;
        const current = window.tmConsent ?? {};
        CATEGORIES.forEach((k) => {
            const input = this.input(k);
            if (input) {
                input.checked = current[k] === true;
            }
        });
        this.dialogTarget.showModal();
    }

    closePreferences(event) {
        event?.preventDefault();
        this.dialogTarget.close();
    }

    restoreFocus() {
        if (this.opener && document.contains(this.opener) && this.opener.offsetParent !== null) {
            this.opener.focus();
        }
    }

    input(category) {
        return this.dialogTarget.querySelector(`input[name="${category}"]`);
    }

    persist(choices, source) {
        const previous = window.tmConsent ?? {};
        const withdrawn = CATEGORIES.some((k) => previous[k] === true && choices[k] !== true);

        writeConsent(choices, this.versionValue, this.monthsValue);
        if (this.endpointValue && this.endpointValue !== '#') {
            fetch(this.endpointValue, {
                method: 'POST',
                credentials: 'same-origin',
                keepalive: true,
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ choices, source, version: this.versionValue }),
            }).catch(() => { /* la preuve serveur est best effort ; le choix reste appliqué */ });
        }

        this.bannerTarget.hidden = true;
        if (this.dialogTarget.open) {
            this.dialogTarget.close();
        }
        if (withdrawn) {
            // Retrait de consentement : recharger pour arrêter les traceurs déjà chargés.
            window.location.reload();
            return;
        }
        this.apply(choices);
    }

    apply(choices) {
        window.tmConsent = { ...choices };
        document.querySelectorAll('script[type="text/plain"][data-consent-category]').forEach((placeholder) => {
            if (choices[placeholder.dataset.consentCategory] !== true || placeholder.dataset.consentActivated) {
                return;
            }
            const script = document.createElement('script');
            if (placeholder.dataset.src) {
                script.src = placeholder.dataset.src;
                script.async = true;
            } else {
                script.textContent = placeholder.textContent;
            }
            if (placeholder.nonce) {
                script.nonce = placeholder.nonce;
            }
            placeholder.dataset.consentActivated = '1';
            placeholder.after(script);
        });
        window.dispatchEvent(new CustomEvent('tm:consent-changed', { detail: { ...choices } }));
    }
}
