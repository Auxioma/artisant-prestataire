<?php

declare(strict_types=1);

namespace App\Search\Application\Directory;

use App\Shared\Application\Bus\Query;

/**
 * Sitemap « villes » (BO12.3, RG-023) : pages métier × ville, ville et département indexables (seuil `seo.min_pros_index`,
 * rayon `search.default_radius_km`, comme les pages elles-mêmes).
 * Réponse : list<array{city: ?string, job: ?string, department?: string, count: int}>.
 */
final readonly class SitemapLocalPages implements Query
{
}
