import { Controller } from '@hotwired/stimulus';

/*
 * Mot de passe (CDC A02) : afficher / masquer (bouton à aria-pressed) et jauge de robustesse indicative.
 * La jauge n'impose rien (aucune règle de composition, ASVS 2.1.9) : longueur et variété, la décision reste serveur
 * (longueur minimale paramétrée + Have I Been Pwned).
 */
export default class extends Controller {
    static targets = ['input', 'toggle', 'showIcon', 'hideIcon', 'gauge', 'fill', 'label'];

    toggle() {
        const visible = this.inputTarget.type === 'password';
        this.inputTarget.type = visible ? 'text' : 'password';
        this.toggleTarget.setAttribute('aria-pressed', String(visible));
        this.toggleTarget.setAttribute('aria-label', visible ? 'Masquer le mot de passe' : 'Afficher le mot de passe');
        this.showIconTarget.toggleAttribute('hidden', visible);
        this.hideIconTarget.toggleAttribute('hidden', !visible);
    }

    measure() {
        if (!this.hasGaugeTarget) {
            return;
        }
        const value = this.inputTarget.value;
        const min = Number(this.inputTarget.getAttribute('minlength') || 12);
        this.gaugeTarget.hidden = value.length === 0;
        const level = this.level(value, min);
        const labels = ['Trop court', 'Faible', 'Correct', 'Robuste'];
        this.gaugeTarget.dataset.level = String(level);
        this.fillTarget.style.setProperty('--strength', String((level + 1) / labels.length));
        this.labelTarget.textContent = `Robustesse : ${labels[level]}`;
    }

    level(value, min) {
        const length = [...value].length;
        if (length < min) {
            return 0;
        }
        const classes = [/[a-z]/, /[A-Z]/, /[0-9]/, /[^A-Za-z0-9]/].filter((re) => re.test(value)).length;
        const unique = new Set(value).size;
        if (unique < 5) {
            return 1;
        }
        if (length >= min + 8 || (length >= min + 4 && classes >= 3)) {
            return 3;
        }

        return length >= min + 2 || classes >= 3 ? 2 : 1;
    }
}
