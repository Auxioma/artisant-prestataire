import { hasConsent } from './consent.js';

/*
 * Événements analytics (CDC §1.3 : snake_case, dataLayer compatible GA4 / Matomo).
 * Rien n'est poussé sans consentement « mesure d'audience » (cadrage §6).
 */
const SNAKE_CASE = /^[a-z][a-z0-9]*(_[a-z0-9]+)*$/;

export function track(event, params = {}) {
    if (!SNAKE_CASE.test(event)) {
        throw new Error(`Événement analytics « ${event} » : nom snake_case attendu`);
    }
    if (!hasConsent('audience')) {
        return false;
    }
    window.dataLayer = window.dataLayer || [];
    window.dataLayer.push({ event, ...params });

    return true;
}
