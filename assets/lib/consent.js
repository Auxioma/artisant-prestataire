/*
 * État du consentement cookies partagé (CDC §3.2) : cookie propriétaire « tm_consent » (sans donnée personnelle),
 * valable `months` mois et lié à la version de la politique. Aucune catégorie optionnelle n'est accordée par défaut.
 */
export const CATEGORIES = ['audience', 'social', 'marketing'];
const COOKIE = 'tm_consent';
const DAY_MS = 86_400_000;
const DAYS_PER_MONTH = 30.44;

export function readConsent(version, months) {
    const raw = document.cookie.split('; ').find((c) => c.startsWith(`${COOKIE}=`));
    if (!raw) {
        return null;
    }
    try {
        const data = JSON.parse(decodeURIComponent(raw.slice(COOKIE.length + 1)));
        const fresh = typeof data.t === 'number' && Date.now() - data.t < months * DAYS_PER_MONTH * DAY_MS;
        if (data.v !== version || !fresh || typeof data.c !== 'object' || data.c === null) {
            return null;
        }
        return Object.fromEntries(CATEGORIES.map((k) => [k, data.c[k] === true]));
    } catch {
        return null;
    }
}

export function writeConsent(choices, version, months) {
    const value = encodeURIComponent(JSON.stringify({ v: version, t: Date.now(), c: choices }));
    const maxAge = Math.round(months * DAYS_PER_MONTH * 86_400);
    const secure = window.location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = `${COOKIE}=${value}; Max-Age=${maxAge}; Path=/; SameSite=Lax${secure}`;
}

export function hasConsent(category) {
    return window.tmConsent?.[category] === true;
}
