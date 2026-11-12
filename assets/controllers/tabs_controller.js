import { Controller } from '@hotwired/stimulus';
import { track } from '../lib/analytics.js';

/*
 * Onglets accessibles (motif ARIA « tabs ») reflétés dans l'ancre de l'URL (P12 : #particuliers, #professionnels).
 * Sans JavaScript, tous les panneaux restent visibles et les onglets sont des liens d'ancre.
 */
export default class extends Controller {
    static targets = ['tab', 'panel'];
    static values = { event: String };

    connect() {
        this.element.classList.add('is-enhanced');
        const fromHash = this.tabTargets.find((t) => t.getAttribute('href') === window.location.hash);
        this.select(fromHash || this.tabTargets[0], false);
    }

    open(event) {
        event.preventDefault();
        this.select(event.currentTarget, true);
        history.replaceState(null, '', event.currentTarget.getAttribute('href'));
    }

    keydown(event) {
        const index = this.tabTargets.indexOf(event.currentTarget);
        const next = { ArrowRight: index + 1, ArrowLeft: index - 1, Home: 0, End: this.tabTargets.length - 1 }[event.key];
        if (next === undefined) {
            return;
        }
        event.preventDefault();
        const tab = this.tabTargets[(next + this.tabTargets.length) % this.tabTargets.length];
        tab.focus();
        this.select(tab, true);
        history.replaceState(null, '', tab.getAttribute('href'));
    }

    select(tab, user) {
        this.tabTargets.forEach((t) => {
            const active = t === tab;
            t.setAttribute('aria-selected', String(active));
            t.tabIndex = active ? 0 : -1;
        });
        this.panelTargets.forEach((p) => {
            p.hidden = `#${p.id}` !== tab.getAttribute('href');
        });
        if (user && this.eventValue) {
            track(this.eventValue, { type: tab.getAttribute('href').slice(1) });
        }
    }
}
