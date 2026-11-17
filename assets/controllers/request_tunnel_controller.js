import { Controller } from '@hotwired/stimulus';

/*
 * P11 — tunnel de dépôt de demande : sauvegarde automatique du brouillon toutes les 10 s quand la saisie a changé
 * (P11.1, RG-060), catégorie → prestations (étape 1), questions conditionnelles (étape 2), envoi des photos avec
 * progression (étape 3), ville et adresse suggérées (étape 4). Sans JavaScript, le tunnel reste utilisable : chaque
 * étape est enregistrée à l'envoi du formulaire.
 */
const AUTOSAVE_MS = 10000;

export default class extends Controller {
    static targets = ['form', 'status', 'category', 'jobSelect', 'question', 'address', 'addressList', 'addressInsee', 'addressPostal', 'dateField',
        'upload', 'uploadSubmit', 'files', 'city', 'cityInsee', 'cityList'];
    static values = { url: String, step: Number };

    connect() {
        this.dirty = false;
        this.timer = window.setInterval(() => this.save(), AUTOSAVE_MS);
        this.refreshQuestions();
        this.filterJobs();
        if (this.hasUploadSubmitTarget) {
            this.uploadSubmitTarget.hidden = true; // envoi immédiat avec progression
        }
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

    /* Étape 4 : suggestions d'adresses (BAN via /api/adresses, dans la ville choisie), au clavier comme à la souris. */
    suggestAddress(event) {
        const input = event.target;
        this.addressInseeTarget.value = '';
        this.addressPostalTarget.value = '';
        window.clearTimeout(this.addressTimer);
        const query = input.value.trim();
        if (query.length < 3) {
            this.closeList(input, this.addressListTarget);
            return;
        }
        this.addressTimer = window.setTimeout(async () => {
            try {
                const insee = this.hasCityInseeTarget ? this.cityInseeTarget.value : '';
                const url = `${this.addressTarget.dataset.url}?q=${encodeURIComponent(query)}${insee ? `&insee=${encodeURIComponent(insee)}` : ''}`;
                const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                const data = response.ok ? await response.json() : { items: [] };
                this.renderList(input, this.addressListTarget, data.items || [], (item) => {
                    input.value = item.label;
                    this.addressInseeTarget.value = item.insee;
                    this.addressPostalTarget.value = item.postal_code;
                    this.dirty = true;
                });
            } catch {
                this.closeList(input, this.addressListTarget);
            }
        }, 250);
    }

    /* Liste de suggestions en combobox ARIA (flèches, Entrée, Échap). */
    renderList(input, list, items, choose) {
        list.replaceChildren();
        items.forEach((item, index) => {
            const option = document.createElement('li');
            option.id = `${list.id}_${index}`;
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', 'false');
            option.textContent = item.label;
            option.addEventListener('mousedown', (e) => {
                e.preventDefault();
                choose(item);
                this.closeList(input, list);
            });
            list.append(option);
        });
        list.hidden = items.length === 0;
        input.setAttribute('aria-expanded', String(items.length > 0));
        list.choose = (index) => {
            choose(items[index]);
            this.closeList(input, list);
        };
        list.activeIndex = -1;
    }

    keyboard(event, list) {
        const options = Array.from(list.querySelectorAll('[role="option"]'));
        if (list.hidden || options.length === 0) {
            return;
        }
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const step = event.key === 'ArrowDown' ? 1 : -1;
            list.activeIndex = (list.activeIndex + step + options.length) % options.length;
            options.forEach((o, i) => o.setAttribute('aria-selected', String(i === list.activeIndex)));
            event.target.setAttribute('aria-activedescendant', options[list.activeIndex].id);
        } else if (event.key === 'Enter' && list.activeIndex >= 0) {
            event.preventDefault();
            list.choose(list.activeIndex);
        } else if (event.key === 'Escape') {
            this.closeList(event.target, list);
        }
    }

    addressKey(event) {
        this.keyboard(event, this.addressListTarget);
    }

    cityKey(event) {
        this.keyboard(event, this.cityListTarget);
    }

    closeList(input, list) {
        list.hidden = true;
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

    /* Étape 5 (Figma 5:162) : « afficher / masquer » le mot de passe. */
    togglePassword(event) {
        const input = this.element.querySelector('#password');
        if (!input) {
            return;
        }
        const visible = input.type === 'password';
        input.type = visible ? 'text' : 'password';
        event.currentTarget.setAttribute('aria-pressed', String(visible));
    }

    /* Étape 1 (Figma 5:2) : la carte de catégorie choisie restreint la liste « Type de prestation ». */
    filterJobs() {
        if (!this.hasJobSelectTarget) {
            return;
        }
        const chosen = this.categoryTargets.map((card) => card.querySelector('input')).find((input) => input.checked);
        Array.from(this.jobSelectTarget.querySelectorAll('optgroup')).forEach((group) => {
            const visible = !chosen || group.label === chosen.value;
            group.hidden = !visible;
            group.disabled = !visible;
        });
        const selected = this.jobSelectTarget.selectedOptions[0];
        if (selected && selected.parentElement.disabled) {
            this.jobSelectTarget.value = '';
        }
    }

    /* Étape 3 (Figma 99:2230) : envoi immédiat de chaque fichier, avec progression, puis rechargement de la liste. */
    filesChosen(event) {
        this.uploadFiles(Array.from(event.target.files || []));
        event.target.value = '';
    }

    dragOver(event) {
        event.preventDefault();
        this.uploadTarget.classList.add('tm-dropzone--over');
    }

    dragLeave() {
        this.uploadTarget.classList.remove('tm-dropzone--over');
    }

    drop(event) {
        event.preventDefault();
        this.dragLeave();
        this.uploadFiles(Array.from(event.dataTransfer?.files || []));
    }

    async uploadFiles(files) {
        if (files.length === 0) {
            return;
        }
        const token = this.uploadTarget.querySelector('input[name="_token"]').value;
        for (const file of files) {
            await this.uploadOne(file, token);
        }
        window.location.reload();
    }

    uploadOne(file, token) {
        const tile = document.createElement('li');
        tile.className = 'tm-request-file';
        const preview = document.createElement('div');
        preview.className = 'tm-request-file__preview';
        const bar = document.createElement('progress');
        bar.className = 'tm-request-file__progress';
        bar.max = 100;
        bar.value = 0;
        const label = document.createElement('span');
        label.className = 'tm-request-file__name';
        label.textContent = `0 % · ${file.name}`;
        tile.append(preview, bar, label);
        this.filesTarget.append(tile);

        return new Promise((resolve) => {
            const data = new FormData();
            data.append('_token', token);
            data.append('files[]', file, file.name);
            const xhr = new XMLHttpRequest();
            xhr.open('POST', this.uploadTarget.action);
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.upload.addEventListener('progress', (e) => {
                if (e.lengthComputable) {
                    const pct = Math.round((e.loaded / e.total) * 100);
                    bar.value = pct;
                    label.textContent = `${pct} % · ${file.name}`;
                }
            });
            xhr.addEventListener('loadend', () => resolve());
            xhr.send(data);
        });
    }

    /* Étape 4 (Figma 5:110) : « Ville ou code postal » (référentiel des communes), qui restreint ensuite l'adresse. */
    suggestCity(event) {
        const input = event.target;
        this.cityInseeTarget.value = '';
        window.clearTimeout(this.cityTimer);
        const query = input.value.trim();
        if (query.length < 2) {
            this.closeList(input, this.cityListTarget);
            return;
        }
        this.cityTimer = window.setTimeout(async () => {
            try {
                const response = await fetch(`${input.dataset.url}?type=lieu&q=${encodeURIComponent(query)}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                const data = response.ok ? await response.json() : { lieux: [] };
                const items = (data.lieux || []).map((p) => ({ label: `${p.nom} (${p.codesPostaux[0] || ''})`, insee: p.insee, postal: p.codesPostaux[0] || '' }));
                this.renderList(input, this.cityListTarget, items, (item) => {
                    input.value = item.label;
                    this.cityInseeTarget.value = item.insee;
                    this.dirty = true;
                });
            } catch {
                this.closeList(input, this.cityListTarget);
            }
        }, 200);
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

}
