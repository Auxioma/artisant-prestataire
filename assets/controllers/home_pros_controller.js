import { Controller } from '@hotwired/stimulus';
import { storedPlace } from './home_search_controller.js';

/* P01.6 — recharge les professionnels autour du dernier lieu mémorisé ; « modifier » ramène au champ « Où ? ». */
export default class extends Controller {
    static targets = ['list'];
    static values = { url: String };

    async connect() {
        const place = storedPlace();
        if (!place) {
            return;
        }
        try {
            const separator = this.urlValue.includes('?') ? '&' : '?';
            const response = await fetch(`${this.urlValue}${separator}lieu=${encodeURIComponent(place.insee)}`, { headers: { Accept: 'text/html' } });
            if (response.ok) {
                this.listTarget.innerHTML = await response.text();
            }
        } catch {
            // sélection nationale conservée
        }
    }

    change(event) {
        event.preventDefault();
        const field = document.getElementById('home_place');
        if (field) {
            field.focus();
            field.select();
        }
    }
}
