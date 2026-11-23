<?php

declare(strict_types=1);

namespace App\Search\UI\Http;

use App\Catalog\Application\Geo\Query\CommunesByInsee;
use App\Catalog\Application\Geo\Query\CommuneSummary;
use App\Catalog\Application\Geo\Query\FindCommunes;
use App\Shared\Application\Bus\QueryBus;

/** Lieu saisi dans les pages de découverte (P08, P09) : même règle que la recherche P02. */
final readonly class PlaceResolver
{
    public function __construct(private QueryBus $queries)
    {
    }

    /** Par code INSEE (suggestion choisie) ou, à défaut, par le libellé saisi (commune la plus peuplée qui correspond). */
    public function resolve(string $insee, string $label): ?CommuneSummary
    {
        if (1 === preg_match('/^(\d{2}|2A|2B)\d{3}$/', $insee)) {
            $found = $this->queries->ask(new CommunesByInsee([$insee]));
            if (\is_array($found) && ($found[0] ?? null) instanceof CommuneSummary) {
                return $found[0];
            }
        }
        if (mb_strlen($label) >= 2) {
            $found = $this->queries->ask(new FindCommunes(mb_substr((string) preg_replace('/\s*\(.*$/', '', $label), 0, 60), 1));
            if (\is_array($found) && ($found[0] ?? null) instanceof CommuneSummary) {
                return $found[0];
            }
        }

        return null;
    }

    public static function label(CommuneSummary $commune): string
    {
        return [] === $commune->postalCodes ? $commune->name : \sprintf('%s (%s)', $commune->name, min($commune->postalCodes));
    }
}
