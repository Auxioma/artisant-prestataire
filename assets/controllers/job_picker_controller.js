import { Controller } from '@hotwired/stimulus';

/*
 * A03.3 — choix des métiers : recherche (nom et synonymes), plafond pro.max_jobs (cases désactivées au-delà),
 * métier principal choisi parmi les métiers cochés (le premier coché par défaut). Le serveur revalide tout.
 */
export default class extends Controller {
    static targets = ['job', 'category', 'count'];
    static values = { max: Number };

    connect() {
        this.update();
    }

    filter(event) {
        const term = event.target.value.trim().toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
        this.jobTargets.forEach((job) => {
            const haystack = job.dataset.search.normalize('NFD').replace(/[̀-ͯ]/g, '');
            job.hidden = term !== '' && !haystack.includes(term);
        });
        this.categoryTargets.forEach((category) => {
            const visible = category.querySelectorAll('[data-job-picker-target="job"]:not([hidden])').length > 0;
            category.hidden = !visible;
            if (term !== '' && visible) {
                category.open = true;
            }
        });
    }

    update() {
        const boxes = this.jobTargets.map((job) => job.querySelector('input[type="checkbox"]'));
        const checked = boxes.filter((box) => box.checked);
        boxes.forEach((box) => {
            box.disabled = !box.checked && checked.length >= this.maxValue;
            const radio = box.closest('[data-job-picker-target="job"]').querySelector('input[type="radio"]');
            radio.disabled = !box.checked;
            if (!box.checked) {
                radio.checked = false;
            }
        });
        const radios = this.jobTargets.map((job) => job.querySelector('input[type="radio"]'));
        if (checked.length > 0 && !radios.some((radio) => radio.checked)) {
            checked[0].closest('[data-job-picker-target="job"]').querySelector('input[type="radio"]').checked = true;
        }
        this.countTarget.textContent = `Métiers sélectionnés (${checked.length}/${this.maxValue})`;
    }
}
