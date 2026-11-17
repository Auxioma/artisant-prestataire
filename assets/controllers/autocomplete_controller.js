import { Controller } from '@hotwired/stimulus';

/*
 * Saisie assistée métier / lieu (P01.2, P02.1) sur /api/suggestions : combobox ARIA (flèches, Entrée, Échap), la
 * suggestion choisie remplit le champ caché (slug du métier ou code INSEE). Sans JavaScript, le libellé saisi suffit :
 * le serveur le résout.
 */
export default class extends Controller {
    static targets = ['input', 'value', 'list'];
    static values = { url: String, scope: String };

    connect() {
        const input = this.inputTarget;
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-expanded', 'false');
        input.setAttribute('aria-controls', this.listTarget.id);
        input.addEventListener('keydown', (event) => this.navigate(event));
        input.addEventListener('blur', () => setTimeout(() => this.close(), 150));
    }

    suggest() {
        this.valueTarget.value = '';
        clearTimeout(this.timer);
        const term = this.inputTarget.value.trim();
        if (term.length < 2) {
            this.close();
            return;
        }
        this.timer = setTimeout(async () => {
            try {
                const response = await fetch(`${this.urlValue}?type=${this.scopeValue}&q=${encodeURIComponent(term)}`, { headers: { Accept: 'application/json' } });
                if (!response.ok) {
                    return;
                }
                const data = await response.json();
                const items = this.scopeValue === 'lieu'
                    ? (data.lieux || []).map((l) => ({ value: l.insee, label: `${l.nom} (${(l.codesPostaux || [])[0] || ''})`, hint: l.departement }))
                    : (data.metiers || []).map((m) => ({ value: m.slug, label: m.nom, hint: m.categorie }));
                this.render(items);
            } catch {
                // saisie libre : le serveur résout le libellé
            }
        }, 180);
    }

    render(items) {
        this.listTarget.replaceChildren(...items.map((item, index) => {
            const option = document.createElement('li');
            option.id = `${this.listTarget.id}_${index}`;
            option.className = 'tm-area__suggestion';
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', 'false');
            option.textContent = item.label;
            if (item.hint) {
                const hint = document.createElement('span');
                hint.className = 'tm-caption';
                hint.textContent = ` · ${item.hint}`;
                option.append(hint);
            }
            option.dataset.value = item.value;
            option.dataset.label = item.label;
            option.addEventListener('mousedown', (event) => {
                event.preventDefault();
                this.pick(option);
            });
            return option;
        }));
        this.listTarget.hidden = items.length === 0;
        this.inputTarget.setAttribute('aria-expanded', String(items.length > 0));
    }

    navigate(event) {
        const options = [...this.listTarget.querySelectorAll('[role="option"]')];
        if (this.listTarget.hidden || options.length === 0) {
            return;
        }
        const current = options.findIndex((o) => o.getAttribute('aria-selected') === 'true');
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const next = event.key === 'ArrowDown' ? (current + 1) % options.length : (current - 1 + options.length) % options.length;
            options.forEach((o, i) => o.setAttribute('aria-selected', String(i === next)));
            this.inputTarget.setAttribute('aria-activedescendant', options[next].id);
        } else if (event.key === 'Enter' && current >= 0) {
            event.preventDefault();
            this.pick(options[current]);
        } else if (event.key === 'Escape') {
            this.close();
        }
    }

    pick(option) {
        this.inputTarget.value = option.dataset.label;
        this.valueTarget.value = option.dataset.value;
        this.close();
        this.dispatch('selected', { detail: { scope: this.scopeValue, value: option.dataset.value } });
    }

    close() {
        this.listTarget.hidden = true;
        this.inputTarget.setAttribute('aria-expanded', 'false');
        this.inputTarget.removeAttribute('aria-activedescendant');
    }
}
