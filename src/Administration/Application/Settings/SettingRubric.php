<?php

declare(strict_types=1);

namespace App\Administration\Application\Settings;

use App\Shared\Application\Settings\SettingKey;

/**
 * BO13.1 — rubriques des paramètres (Figma 186:5725). Chaque réglage appartient à la première rubrique dont un préfixe
 * ou une clé correspond ; les réglages SEO restent dans BO12. Rubriques sans réglage éditable : informations seules.
 */
final class SettingRubric
{
    /** clé => [libellé, préfixes ou clés exactes] */
    public const array RUBRICS = [
        'identite' => ['Identité de l’éditeur', []],
        'reseaux' => ['Réseaux sociaux', ['social.']],
        'demandes' => ['Demandes et devis', ['requests.max', 'requests.expiry', 'requests.duplicate', 'requests.direct', 'requests.reopen', 'requests.relaunch', 'requests.dispatch', 'requests.digest', 'requests.urgent', 'requests.min', 'quotes.', 'appointments.', 'features.request_reopen', 'requests.']],
        'credits' => ['Crédits', ['credits.', 'plans.']],
        'recherche' => ['Recherche et classement', ['search.', 'ranking.', 'availability.', 'home.', 'catalog.', 'platform.', 'features.sponsored']],
        'pros' => ['Professionnels et justificatifs', ['pro.', 'documents.expiry', 'documents.hide', 'documents.signed', 'documents.delete']],
        'avis' => ['Avis', ['reviews.']],
        'moderation' => ['Modération', ['moderation.']],
        'antifraude' => ['Anti-fraude', ['antifraud.']],
        'cartographie' => ['Cartographie', []],
        'emails' => ['Emails (SMTP / API)', ['notifications.message', 'contact.', 'newsletter.', 'messages.']],
        'sms' => ['SMS', ['notifications.sms']],
        'paiement' => ['Paiement (Stripe)', ['billing.']],
        'authentification' => ['Authentification (2FA, sociale)', ['auth.', 'admin.', 'features.custom_admin_roles']],
        'cookies' => ['Cookies / CMP', ['cookies.']],
        'analytics' => ['Analytics', []],
        'maintenance' => ['Maintenance', ['site.']],
        'rgpd' => ['RGPD (conservation)', ['gdpr.', 'audit.', 'documents.retention', 'requests.retention', 'requests.draft_retention', 'messages.retention']],
    ];
    private const array EXCLUDED = ['seo.'];

    /** Rubriques prioritaires pour les clés partagées (conservation avant thème métier). */
    private const array PRIORITY = ['rgpd', 'sms'];

    public static function of(SettingKey $key): ?string
    {
        foreach (self::EXCLUDED as $prefix) {
            if (str_starts_with($key->value, $prefix)) {
                return null;
            }
        }
        foreach ([...self::PRIORITY, ...array_keys(self::RUBRICS)] as $rubric) {
            foreach (self::RUBRICS[$rubric][1] as $match) {
                if (str_starts_with($key->value, $match)) {
                    return $rubric;
                }
            }
        }

        return null;
    }

    /** @return list<SettingKey> */
    public static function keys(string $rubric): array
    {
        return array_values(array_filter(SettingKey::cases(), static fn (SettingKey $k): bool => self::of($k) === $rubric));
    }
}
