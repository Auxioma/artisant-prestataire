/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/*
 * A03.4 — zone d'intervention : bascule rayon / liste, suggestions de communes (combobox ARIA : flèches, Entrée,
 * Échap), aperçu de couverture en direct. Sans JavaScript, le formulaire fonctionne seul ; le serveur revalide tout.
 */
export default class extends Controller {
    static targets = ['radiusPanel', 'listPanel', 'center', 'centerInsee', 'centerList', 'communeSearch', 'communeList', 'communePick',
        'radius', 'radiusValue', 'coverage', 'refreshButton'];
    static values = { previewUrl: String, communesUrl: String };

    connect() {
        this.timers = {};
        this.refreshButtonTarget.hidden = true; // l'aperçu se met à jour seul
        [[this.centerTarget, this.centerListTarget], [this.communeSearchTarget, this.communeListTarget]].forEach(([field, list]) => {
            const input = this.input(field);
            input.setAttribute('role', 'combobox');
            input.setAttribute('aria-autocomplete', 'list');
            input.setAttribute('aria-expanded', 'false');
            input.setAttribute('aria-controls', list.id);
            input.addEventListener('keydown', (event) => this.navigate(event, input, list));
            input.addEventListener('blur', () => setTimeout(() => this.close(input, list), 150));
        });
    }

    input(element) {
        return element.matches('input') ? element : element.querySelector('input');
    }

    switchMode(event) {
        const radius = event.target.value === 'radius';
        this.radiusPanelTarget.hidden = !radius;
        this.listPanelTarget.hidden = radius;
        this.refresh();
    }

    radiusChanged() {
        this.radiusValueTarget.textContent = `${this.radiusTarget.value} km`;
        this.refresh();
    }

    suggest(event) {
        const input = event.target;
        const isCenter = this.centerTarget.contains(input);
        const list = isCenter ? this.centerListTarget : this.communeListTarget;
        if (isCenter) {
            this.centerInseeTarget.value = ''; // saisie modifiée : commune à choisir de nouveau
        }
        clearTimeout(this.timers.suggest);
        const term = input.value.trim();
        if (term.length < 2) {
            this.close(input, list);
            return;
        }
        this.timers.suggest = setTimeout(async () => {
            try {
                const response = await fetch(`${this.communesUrlValue}?q=${encodeURIComponent(term)}`, { headers: { Accept: 'application/json' } });
                if (!response.ok) {
                    return;
                }
                this.render(input, list, await response.json(), isCenter);
            } catch {
                // hors ligne : la saisie libre reste possible (résolution côté serveur)
            }
        }, 200);
    }

    render(input, list, communes, isCenter) {
        list.replaceChildren(...communes.map((commune, index) => {
            const option = document.createElement('li');
            option.id = `${list.id}_${index}`;
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', 'false');
            option.className = 'tm-area__suggestion';
            option.textContent = commune.label;
            option.addEventListener('mousedown', (event) => {
                event.preventDefault();
                this.pick(input, list, commune, isCenter);
            });
            option.dataset.insee = commune.insee;
            option.dataset.label = commune.label;
            return option;
        }));
        list.hidden = communes.length === 0;
        input.setAttribute('aria-expanded', String(communes.length > 0));
        input.removeAttribute('aria-activedescendant');
    }

    navigate(event, input, list) {
        const options = [...list.querySelectorAll('[role="option"]')];
        if (list.hidden || options.length === 0) {
            return;
        }
        const current = options.findIndex((o) => o.getAttribute('aria-selected') === 'true');
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const next = event.key === 'ArrowDown' ? (current + 1) % options.length : (current - 1 + options.length) % options.length;
            options.forEach((o, i) => o.setAttribute('aria-selected', String(i === next)));
            input.setAttribute('aria-activedescendant', options[next].id);
        } else if (event.key === 'Enter' && current >= 0) {
            event.preventDefault();
            const option = options[current];
            this.pick(input, list, { insee: option.dataset.insee, label: option.dataset.label }, this.centerTarget.contains(input));
        } else if (event.key === 'Escape') {
            this.close(input, list);
        }
    }

    pick(input, list, commune, isCenter) {
        this.close(input, list);
        if (isCenter) {
            input.value = commune.label;
            this.centerInseeTarget.value = commune.insee;
            this.refresh();
        } else {
            // Ajout immédiat : le serveur enregistre la commune choisie et réaffiche la liste.
            this.communePickTarget.value = commune.insee;
            const button = this.element.querySelector('button[value="add_commune"]');
            this.element.requestSubmit(button);
        }
    }

    close(input, list) {
        list.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
    }

    refresh() {
        clearTimeout(this.timers.preview);
        this.timers.preview = setTimeout(() => this.preview(), 250);
    }

    async preview() {
        const form = new FormData(this.element);
        const params = new URLSearchParams();
        const mode = form.get('mode') === 'list' ? 'list' : 'radius';
        params.set('mode', mode);
        if (mode === 'radius') {
            if (!this.centerInseeTarget.value) {
                return;
            }
            params.set('center', this.centerInseeTarget.value);
            params.set('radius', this.radiusTarget.value);
        } else {
            form.getAll('departments[]').forEach((code) => params.append('departments[]', code));
            form.getAll('communes[]').forEach((code) => params.append('communes[]', code));
        }
        try {
            const response = await fetch(`${this.previewUrlValue}?${params}`, { headers: { Accept: 'application/json' } });
            if (!response.ok) {
                return;
            }
            const { communes, population } = await response.json();
            const n = new Intl.NumberFormat('fr-FR');
            this.coverageTarget.textContent = communes > 0
                ? `${n.format(communes)} commune${communes > 1 ? 's' : ''} couverte${communes > 1 ? 's' : ''} · ${n.format(population)} habitants`
                : 'Choisissez votre zone pour voir les communes couvertes.';
        } catch {
            this.refreshButtonTarget.hidden = false;
        }
    }
}
