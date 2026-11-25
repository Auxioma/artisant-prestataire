<?php

declare(strict_types=1);

namespace App\Search\Application\Directory;

use App\Shared\Application\Bus\Query;

/** Sitemap « artisans » : fiches publiques. Réponse : list<array{id: string, name: string, commune: ?string, updated_at: string}>. */
final readonly class SitemapArtisans implements Query
{
}
