import { Controller } from '@hotwired/stimulus';
import { track } from '../lib/analytics.js';

/*
 * P01.2 — recherche de l'accueil : dernier lieu mémorisé (stockage local « nécessaire », sans donnée personnelle hors
 * la commune choisie) et géolocalisation sur demande (géocodage inverse côté serveur, coordonnées non conservées).
 */
export const PLACE_KEY = 'tm.last_place';

export function storedPlace() {
    try {
        const place = JSON.parse(window.localStorage.getItem(PLACE_KEY) || 'null');
        return place && /^(\d{2}|2A|2B)\d{3}$/.test(place.insee) ? place : null;
    } catch {
        return null;
    }
}

export default class extends Controller {
    static targets = ['label', 'insee', 'status', 'locate'];
    static values = { url: String };

    connect() {
        const place = storedPlace();
        if (place && !this.labelTarget.value) {
            this.labelTarget.value = place.label;
            this.inseeTarget.value = place.insee;
        }
        if ('geolocation' in navigator) {
            this.locateTarget.hidden = false;
        }
    }

    remember() {
        const job = this.element.querySelector('input[name="metier"]');
        track('search_submitted', { metier: job?.value || null, lieu: this.inseeTarget.value || null, geoloc: this.located === true });
        if (/^(\d{2}|2A|2B)\d{3}$/.test(this.inseeTarget.value)) {
            try {
                window.localStorage.setItem(PLACE_KEY, JSON.stringify({ insee: this.inseeTarget.value, label: this.labelTarget.value }));
            } catch {
                // stockage indisponible : rien à mémoriser
            }
        }
    }

    locate() {
        this.statusTarget.textContent = 'Localisation en cours…';
        navigator.geolocation.getCurrentPosition(async ({ coords }) => {
            try {
                const response = await fetch(`${this.urlValue}?lat=${coords.latitude.toFixed(4)}&lng=${coords.longitude.toFixed(4)}`, { headers: { Accept: 'application/json' } });
                if (!response.ok) {
                    throw new Error();
                }
                const { insee, label } = await response.json();
                this.labelTarget.value = label;
                this.inseeTarget.value = insee;
                this.statusTarget.textContent = `Position : ${label}`;
                this.located = true;
            } catch {
                this.statusTarget.textContent = 'Nous n’avons pas trouvé votre commune. Saisissez votre ville ou code postal.';
            }
        }, () => {
            this.statusTarget.textContent = 'Localisation non autorisée. Saisissez votre ville ou code postal.';
        }, { timeout: 10000, maximumAge: 600000 });
    }
}
