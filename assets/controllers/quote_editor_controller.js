/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/*
 * PR04 — éditeur de devis : brouillon enregistré automatiquement après une pause de saisie (aucun crédit consommé),
 * totaux, totaux de ligne et contrôle RG-120 recalculés par le serveur ; ajout (ligne, section), suppression et
 * réordonnancement des lignes. Sans JavaScript, « Enregistrer brouillon » soumet le formulaire. En révision (RG-102,
 * `revision` vrai), rien n'est enregistré : le serveur recalcule seulement les totaux et le contrôle RG-120.
 */
const AUTOSAVE_DELAY_MS = 1500;

export default class extends Controller {
    static targets = ['lines', 'row', 'template', 'lineTotal', 'ht', 'vat', 'ttc', 'mentions', 'blocked', 'certificate', 'status', 'error', 'send'];
    static values = { url: String, revision: Boolean };

    disconnect() {
        window.clearTimeout(this.timer);
    }

    changed() {
        window.clearTimeout(this.timer);
        this.timer = window.setTimeout(() => this.save(), AUTOSAVE_DELAY_MS);
    }

    addLine(event) {
        const next = this.rowTargets.reduce((max, row) => Math.max(max, this.index(row) + 1), 0);
        const fragment = this.templateTarget.content.cloneNode(true);
        fragment.querySelectorAll('[name], [id], [for]').forEach((el) => {
            for (const attr of ['name', 'id', 'for']) {
                if (el.hasAttribute(attr)) {
                    el.setAttribute(attr, el.getAttribute(attr).replaceAll('__i__', String(next)));
                }
            }
        });
        const row = fragment.querySelector('tr');
        row.querySelector('select[name$="[type]"]').value = event.currentTarget.dataset.type || 'labour';
        this.linesTarget.appendChild(fragment);
        this.applyType(row);
        row.querySelector('input[name$="[label]"]').focus();
    }

    remove(event) {
        event.currentTarget.closest('tr').remove();
        this.changed();
    }

    moveUp(event) {
        const row = event.currentTarget.closest('tr');
        if (row.previousElementSibling) {
            row.parentNode.insertBefore(row, row.previousElementSibling);
            this.changed();
        }
    }

    moveDown(event) {
        const row = event.currentTarget.closest('tr');
        if (row.nextElementSibling) {
            row.parentNode.insertBefore(row.nextElementSibling, row);
            this.changed();
        }
    }

    typeChanged(event) {
        this.applyType(event.currentTarget.closest('tr'));
    }

    applyType(row) {
        const type = row.querySelector('select[name$="[type]"]').value;
        const priced = type !== 'section' && type !== 'comment';
        row.classList.toggle('is-heading', !priced);
        row.querySelectorAll('input[name$="[quantity]"], input[name$="[unit]"], input[name$="[unit_price]"], input[name$="[discount]"], select[name$="[vat]"]').forEach((el) => {
            el.disabled = !priced;
        });
    }

    async save() {
        this.statusTarget.textContent = this.revisionValue ? 'Calcul…' : 'Enregistrement…';
        let response;
        try {
            response = await fetch(this.urlValue, { method: 'POST', body: new FormData(this.element), headers: { Accept: 'application/json' } });
        } catch {
            this.statusTarget.textContent = this.revisionValue ? 'Calcul impossible (connexion) : réessai à la prochaine modification.' : 'Enregistrement impossible (connexion) : réessai à la prochaine modification.';
            return;
        }
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            this.errorTarget.textContent = data.error || (this.revisionValue ? 'Les totaux n’ont pas pu être recalculés.' : 'Le brouillon n’a pas pu être enregistré.');
            this.errorTarget.hidden = false;
            this.statusTarget.textContent = '';
            return;
        }
        this.errorTarget.hidden = true;
        this.statusTarget.textContent = this.revisionValue ? 'Totaux à jour (non envoyé)' : (data.saved_at ? `Brouillon enregistré à ${data.saved_at}` : 'Brouillon enregistré');
        if (data.totals) {
            this.htTarget.textContent = data.totals.ht;
            this.vatTarget.textContent = data.totals.vat;
            this.ttcTarget.textContent = data.totals.ttc;
        }
        this.lineTotalTargets.forEach((cell, i) => {
            cell.textContent = data.lines?.[this.pricedIndex(i)] ?? '';
        });
        const sprite = (this.element.querySelector('use')?.getAttribute('href') || '').split('#')[0];
        this.mentionsTarget.replaceChildren(...(data.mentions || []).map((m) => {
            const li = document.createElement('li');
            li.className = m.ok ? 'is-ok' : 'is-missing';
            const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
            svg.setAttribute('class', 'tm-icon tm-icon--xs tm-icon--inherit');
            svg.setAttribute('aria-hidden', 'true');
            const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
            use.setAttribute('href', `${sprite}#${m.ok ? 'check' : 'alert'}`);
            svg.appendChild(use);
            li.append(svg, m.label);
            return li;
        }));
        this.blockedTarget.hidden = data.complete !== false;
        if (this.hasSendTarget) this.sendTarget.disabled = data.complete === false;
        this.certificateTarget.hidden = !data.certificate;
    }

    /* Les lignes entièrement vides ne sont pas enregistrées : correspondance des totaux par position parmi les lignes remplies. */
    pricedIndex(i) {
        let position = -1;
        for (let k = 0; k <= i; k++) {
            const row = this.rowTargets[k];
            const label = row.querySelector('input[name$="[label]"]').value.trim();
            const price = row.querySelector('input[name$="[unit_price]"]')?.value.trim() ?? '';
            if (label !== '' || price !== '') {
                position++;
            }
        }
        return position;
    }

    index(row) {
        const match = row.querySelector('[name^="lines["]')?.getAttribute('name').match(/^lines\[(\d+)\]/);
        return match ? Number(match[1]) : 0;
    }
}
