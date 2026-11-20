/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/*
 * Inscription client (CDC A02) : validation en ligne à la perte de focus (messages du CDC) et champs « entreprise »
 * affichés seulement pour une entreprise cliente. Le serveur revalide tout ; ce contrôle n'est qu'une aide.
 */
const MESSAGES = {
    lastName: 'Veuillez saisir votre nom.',
    firstName: 'Veuillez saisir votre prénom.',
    email: 'Adresse email invalide.',
    postalCode: 'Code postal inconnu.',
    phone: 'Numéro de téléphone invalide.',
    companyName: 'Veuillez saisir la raison sociale.',
};
const NAME = /^[\p{L}\p{M}][\p{L}\p{M} '’.-]{1,49}$/u;

export default class extends Controller {
    static targets = ['business'];

    connect() {
        this.toggleBusiness();
    }

    toggleBusiness() {
        const business = this.element.querySelector('input[name$="[clientType]"]:checked')?.value === 'professionnel';
        this.businessTargets.forEach((row) => {
            row.hidden = !business;
            row.querySelectorAll('input').forEach((input) => { input.disabled = !business; });
        });
    }

    validateField(event) {
        const input = event.target;
        const key = (input.name.match(/\[(\w+)\](?:\[first\])?$/) || [])[1];
        const value = input.value.trim();
        let message = null;

        if (key === 'lastName' || key === 'firstName') {
            message = NAME.test(value) ? null : MESSAGES[key];
        } else if (key === 'email') {
            message = value !== '' && input.checkValidity() && value.includes('.') ? null : MESSAGES.email;
        } else if (key === 'postalCode') {
            message = /^\d{5}$/.test(value) ? null : MESSAGES.postalCode;
        } else if (key === 'phone') {
            message = value === '' || /^(\+|00)?[0-9 .()-]{8,20}$/.test(value) ? null : MESSAGES.phone;
        } else if (key === 'companyName') {
            message = value.length >= 2 ? null : MESSAGES.companyName;
        } else if (key === 'password') {
            const min = Number(input.getAttribute('minlength') || 12);
            message = [...input.value].length >= min ? null : `Votre mot de passe doit contenir au moins ${min} caractères.`;
        }
        this.show(input, message);
    }

    show(input, message) {
        const field = input.closest('.tm-field');
        if (!field) {
            return;
        }
        const id = `${input.id}_live_error`;
        field.querySelector(`#${CSS.escape(id)}`)?.remove();
        field.classList.toggle('tm-field--error', message !== null);
        const describedBy = (input.getAttribute('aria-describedby') || '').split(' ').filter((t) => t && t !== id);
        if (message === null) {
            input.removeAttribute('aria-invalid');
        } else {
            const error = document.createElement('p');
            error.className = 'tm-field__error';
            error.id = id;
            error.textContent = message;
            field.append(error);
            input.setAttribute('aria-invalid', 'true');
            describedBy.unshift(id);
        }
        if (describedBy.length > 0) {
            input.setAttribute('aria-describedby', describedBy.join(' '));
        } else {
            input.removeAttribute('aria-describedby');
        }
    }
}
