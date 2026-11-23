<?php

declare(strict_types=1);

namespace App\Search\Application\Directory;

use App\Shared\Application\Bus\Query;

/**
 * P07 « Les mieux notés » (/les-mieux-notes) : classement RG-024 par métier, département et période (12 mois ou
 * depuis toujours). Réponse : TopRatedResult.
 */
final readonly class TopRatedArtisans implements Query
{
    public const int LAST_MONTHS = 12;

    /** @param list<string> $jobIds */
    public function __construct(public array $jobIds = [], public ?string $departmentCode = null, public ?int $months = self::LAST_MONTHS, public int $limit = 20)
    {
    }
}
