import { Controller } from '@hotwired/stimulus';
import { track } from '../lib/analytics.js';

/*
 * Événements analytics déclaratifs (CDC §1.3), envoyés seulement avec le consentement « mesure d'audience ».
 *   Au clic : data-action="analytics#track" data-analytics-event-param="signup_type_select" data-analytics-type-param="client"
 *   À l'affichage : data-analytics-auto-event-value="sign_up" data-analytics-auto-type-value="client"
 */
export default class extends Controller {
    static values = { autoEvent: String, autoType: String };

    connect() {
        if (this.autoEventValue) {
            track(this.autoEventValue, this.autoTypeValue ? { type: this.autoTypeValue } : {});
        }
    }

    track({ params }) {
        const { event, ...rest } = params;
        if (event) {
            track(event, rest);
        }
    }
}
