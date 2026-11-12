import { Controller } from '@hotwired/stimulus';
import { track } from '../lib/analytics.js';

/*
 * P03.2 — filtre instantané des catégories (nom, métiers, synonymes ; sans accents). Sans JavaScript, toutes les
 * catégories restent affichées. Événement category_filter_search (une fois par saisie stabilisée).
 */
const normalize = (text) => text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();

export default class extends Controller {
    static targets = ['input', 'item', 'empty', 'status'];

    filter() {
        const term = normalize(this.inputTarget.value);
        let visible = 0;
        this.itemTargets.forEach((item) => {
            const match = term === '' || normalize(item.dataset.terms || '').includes(term);
            item.hidden = !match;
            visible += match ? 1 : 0;
        });
        this.emptyTarget.hidden = visible > 0;
        this.statusTarget.textContent = term === '' ? '' : `${visible} catégorie${visible > 1 ? 's' : ''} correspondante${visible > 1 ? 's' : ''}`;
        clearTimeout(this.timer);
        if (term.length >= 2) {
            this.timer = setTimeout(() => track('category_filter_search', { results: visible }), 800);
        }
    }
}
