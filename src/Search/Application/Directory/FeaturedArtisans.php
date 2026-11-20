<?php

declare(strict_types=1);

namespace App\Search\Application\Directory;

use App\Shared\Application\Bus\Query;

/**
 * Blocs de mise en avant sans lieu connu (P04.3) : artisans éligibles RG-011 (vérifiés, actifs, complétude ≥
 * `pro.min_completion_featured`), les mieux notés d'abord (note bayésienne). Réponse : list<ArtisanHit>.
 */
final readonly class FeaturedArtisans implements Query
{
    /** @param list<string> $jobIds */
    public function __construct(public array $jobIds, public int $limit, public ?string $departmentCode = null)
    {
    }
}
