/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { track } from '../lib/analytics.js';

/*
 * P14.1 — suggestions instantanées dans l'aide (dès 2 caractères, attente 200 ms) ; flèches et Entrée pour choisir,
 * Échap pour fermer. Le formulaire reste utilisable sans JavaScript (page de résultats).
 */
export default class extends Controller {
    static targets = ['input', 'list'];
    static values = { url: String };

    suggest() {
        clearTimeout(this.timer);
        const term = this.inputTarget.value.trim();
        if (term.length < 2) {
            this.close();
            return;
        }
        this.timer = setTimeout(async () => {
            try {
                const response = await fetch(`${this.urlValue}?q=${encodeURIComponent(term)}`, { headers: { Accept: 'application/json' } });
                const { suggestions } = await response.json();
                this.render(suggestions);
                track('help_search', { terme: term.slice(0, 100), resultats: suggestions.length });
            } catch {
                this.close();
            }
        }, 200);
    }

    render(suggestions) {
        this.listTarget.replaceChildren(...suggestions.map((s, i) => {
            const item = document.createElement('li');
            item.setAttribute('role', 'option');
            item.id = `help_suggestion_${i}`;
            const link = document.createElement('a');
            link.href = s.url;
            link.textContent = s.label;
            const topic = document.createElement('span');
            topic.className = 'tm-caption';
            topic.textContent = ` — ${s.topic}`;
            link.append(topic);
            item.append(link);
            return item;
        }));
        const open = suggestions.length > 0;
        this.listTarget.hidden = !open;
        this.inputTarget.setAttribute('aria-expanded', String(open));
        this.index = -1;
    }

    navigate(event) {
        const items = [...this.listTarget.querySelectorAll('[role="option"]')];
        if (event.key === 'Escape') {
            this.close();
            return;
        }
        if (!items.length || !['ArrowDown', 'ArrowUp', 'Enter'].includes(event.key)) {
            return;
        }
        if (event.key === 'Enter') {
            if (this.index >= 0) {
                event.preventDefault();
                items[this.index].querySelector('a').click();
            }
            return;
        }
        event.preventDefault();
        this.index = (this.index + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
        items.forEach((item, i) => item.setAttribute('aria-selected', String(i === this.index)));
        this.inputTarget.setAttribute('aria-activedescendant', items[this.index].id);
    }

    close() {
        this.listTarget.hidden = true;
        this.inputTarget.setAttribute('aria-expanded', 'false');
        this.inputTarget.removeAttribute('aria-activedescendant');
    }
}
