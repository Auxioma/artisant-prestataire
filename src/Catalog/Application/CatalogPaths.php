<?php

declare(strict_types=1);

namespace App\Catalog\Application;

/**
 * Chemins publics du référentiel (CDC annexe A) : /categories/{categorie}, /metiers/{metier}, /{metier}/{ville-cp}.
 */
final class CatalogPaths
{
    public static function category(string $slug): string
    {
        return '/categories/'.$slug;
    }

    public static function job(string $slug): string
    {
        return '/metiers/'.$slug;
    }

    /** Préfixe des pages métier × ville. */
    public static function jobCityPrefix(string $slug): string
    {
        return '/'.$slug.'/';
    }
}
