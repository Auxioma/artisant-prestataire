import { Controller } from '@hotwired/stimulus';

/*
 * P11 — tunnel de dépôt de demande : sauvegarde automatique du brouillon toutes les 10 s quand la saisie a changé
 * (P11.1, RG-060), filtre de la liste des métiers (étape 1), questions conditionnelles (étape 2). Sans JavaScript,
 * le tunnel reste utilisable : chaque étape est enregistrée à l'envoi du formulaire.
 */
const AUTOSAVE_MS = 10000;

export default class extends Controller {
    static targets = ['form', 'status', 'category', 'job', 'question', 'address', 'addressList', 'addressInsee', 'addressPostal', 'dateField'];
    static values = { url: String, step: Number };

    connect() {
        this.dirty = false;
        this.timer = window.setInterval(() => this.save(), AUTOSAVE_MS);
        this.refreshQuestions();
    }

    disconnect() {
        window.clearInterval(this.timer);
    }

    changed() {
        this.dirty = true;
        this.refreshQuestions();
        this.refreshDate();
    }

    /* Étape 4 : le champ date n'est affiché que pour « Date précise ». */
    refreshDate() {
        if (!this.hasDateFieldTarget || !this.hasFormTarget) {
            return;
        }
        const timing = this.formTarget.querySelector('input[name="timing"]:checked');
        this.dateFieldTarget.hidden = !timing || timing.value !== 'date';
    }

    /* Étape 4 : suggestions d'adresses (BAN via /api/adresses), au clavier comme à la souris (combobox ARIA). */
    suggestAddress(event) {
        const input = event.target;
        this.addressInseeTarget.value = '';
        this.addressPostalTarget.value = '';
        window.clearTimeout(this.addressTimer);
        const query = input.value.trim();
        if (query.length < 3) {
            this.closeAddresses(input);
            return;
        }
        this.addressTimer = window.setTimeout(async () => {
            try {
                const url = `${this.addressTarget.dataset.url}?q=${encodeURIComponent(query)}`;
                const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                const data = response.ok ? await response.json() : { items: [] };
                this.renderAddresses(input, data.items || []);
            } catch {
                this.closeAddresses(input);
            }
        }, 250);
    }

    renderAddresses(input, items) {
        const list = this.addressListTarget;
        list.replaceChildren();
        items.forEach((item, index) => {
            const option = document.createElement('li');
            option.id = `address_option_${index}`;
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', 'false');
            option.textContent = item.label;
            option.addEventListener('mousedown', (e) => {
                e.preventDefault();
                this.chooseAddress(input, item);
            });
            option.dataset.item = JSON.stringify(item);
            list.append(option);
        });
        list.hidden = items.length === 0;
        input.setAttribute('aria-expanded', String(items.length > 0));
        this.addressIndex = -1;
    }

    addressKey(event) {
        const options = Array.from(this.addressListTarget.querySelectorAll('[role="option"]'));
        if (this.addressListTarget.hidden || options.length === 0) {
            return;
        }
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const step = event.key === 'ArrowDown' ? 1 : -1;
            this.addressIndex = (this.addressIndex + step + options.length) % options.length;
            options.forEach((o, i) => o.setAttribute('aria-selected', String(i === this.addressIndex)));
            event.target.setAttribute('aria-activedescendant', options[this.addressIndex].id);
        } else if (event.key === 'Enter' && this.addressIndex >= 0) {
            event.preventDefault();
            this.chooseAddress(event.target, JSON.parse(options[this.addressIndex].dataset.item));
        } else if (event.key === 'Escape') {
            this.closeAddresses(event.target);
        }
    }

    chooseAddress(input, item) {
        input.value = item.label;
        this.addressInseeTarget.value = item.insee;
        this.addressPostalTarget.value = item.postal_code;
        this.closeAddresses(input);
        this.dirty = true;
    }

    closeAddresses(input) {
        this.addressListTarget.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
    }

    async save() {
        if (!this.dirty || !this.hasFormTarget) {
            return;
        }
        this.dirty = false;
        const data = new FormData(this.formTarget);
        data.set('step', String(this.stepValue));
        data.delete('action');
        try {
            const response = await fetch(this.urlValue, { method: 'POST', body: data, credentials: 'same-origin', headers: { Accept: 'application/json' } });
            if (response.ok) {
                this.announce(`Brouillon enregistré à ${new Date().toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })}.`);
            } else {
                this.dirty = true;
            }
        } catch {
            this.dirty = true;
            this.announce('Enregistrement automatique impossible pour le moment : votre saisie sera enregistrée à l’étape suivante.');
        }
    }

    filterJobs(event) {
        const query = this.normalize(event.target.value);
        this.jobTargets.forEach((job) => {
            job.hidden = query !== '' && !this.normalize(job.dataset.search || '').includes(query);
        });
        this.categoryTargets.forEach((category) => {
            const visible = category.querySelectorAll('[data-request-tunnel-target="job"]:not([hidden])').length;
            category.hidden = visible === 0;
            if (query !== '') {
                category.open = visible > 0;
            }
        });
    }

    /* Une question conditionnelle n'est affichée que si la réponse à la question dont elle dépend correspond. */
    refreshQuestions() {
        if (!this.hasFormTarget) {
            return;
        }
        this.questionTargets.forEach((question) => {
            const field = question.dataset.showIfField;
            if (!field) {
                return;
            }
            const expected = (question.dataset.showIfValues || '').split(' ');
            const given = Array.from(this.formTarget.querySelectorAll(`[name="answers[${field}]"], [name="answers[${field}][]"]`))
                .filter((input) => (input.type === 'radio' || input.type === 'checkbox' ? input.checked : input.value !== ''))
                .map((input) => input.value);
            question.hidden = !given.some((value) => expected.includes(value));
        });
    }

    announce(message) {
        if (this.hasStatusTarget) {
            this.statusTarget.textContent = message;
        }
    }

    normalize(text) {
        return text.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').trim();
    }
}
