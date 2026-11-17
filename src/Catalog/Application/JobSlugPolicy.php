<?php

declare(strict_types=1);

namespace App\Catalog\Application;

use App\Catalog\Domain\Exception\CatalogRuleViolation;
use App\Shared\Domain\ValueObject\Slug;

/**
 * Les pages métier × ville sont servies sous /{slug-metier}/{slug-ville} (annexe A) : un slug de métier ne doit pas
 * entrer en collision avec un premier segment d'URL réservé par le site.
 */
final class JobSlugPolicy
{
    public const array RESERVED = [
        'admin', 'aide', 'api', 'artisans', 'assets', 'blog', 'categories', 'comment-ca-marche', 'connexion', 'consentement-cookies',
        'contact', 'demande-de-devis', 'disponibilites', 'espace-client', 'espace-pro', 'healthz', 'inscription', 'les-mieux-notes',
        'metiers', 'mot-de-passe-oublie', 'nos-professionnels', 'offres-promotions', 'pages', 'plan-du-site', 'pro', 'recherche',
        'reinitialiser', 'tarifs-professionnels', 'webhooks',
    ];

    public static function assertAllowed(Slug $slug): void
    {
        if (\in_array($slug->value, self::RESERVED, true) || str_starts_with($slug->value, '_')) {
            throw new CatalogRuleViolation(\sprintf('Le slug « %s » est réservé par le site.', $slug->value));
        }
    }
}
