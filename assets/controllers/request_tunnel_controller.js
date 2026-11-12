import { Controller } from '@hotwired/stimulus';

/*
 * P11 — tunnel de dépôt de demande : sauvegarde automatique du brouillon toutes les 10 s quand la saisie a changé
 * (P11.1, RG-060), filtre de la liste des métiers (étape 1), questions conditionnelles (étape 2). Sans JavaScript,
 * le tunnel reste utilisable : chaque étape est enregistrée à l'envoi du formulaire.
 */
const AUTOSAVE_MS = 10000;

export default class extends Controller {
    static targets = ['form', 'status', 'category', 'job', 'question'];
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
