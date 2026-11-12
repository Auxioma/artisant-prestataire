<?php

declare(strict_types=1);

namespace App\Catalog\Application\Geo\Query;

use App\Shared\Application\Bus\Query;

/**
 * Communes dont la page ville SEO est activée (BO07.1, `seo_page_enabled`) : voisines d'une commune (par distance,
 * sans elle), d'un département ou de toute la France (par population). Réponse : list<CommuneSummary>.
 */
final readonly class SeoCommunes implements Query
{
    private function __construct(public ?string $nearInsee, public ?string $departmentCode, public int $limit)
    {
    }

    public static function near(string $insee, int $limit): self
    {
        return new self($insee, null, $limit);
    }

    /** Villes activées les plus peuplées (maillage des pages métier). */
    public static function largest(int $limit): self
    {
        return new self(null, null, $limit);
    }

    public static function ofDepartment(string $code, int $limit): self
    {
        return new self(null, $code, $limit);
    }
}
