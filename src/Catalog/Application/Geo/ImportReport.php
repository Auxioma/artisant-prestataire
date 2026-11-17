<?php

declare(strict_types=1);

namespace App\Catalog\Application\Geo;

final readonly class ImportReport
{
    /**
     * @param list<string> $rejected motifs des enregistrements rejetés (code INSEE : motif)
     */
    public function __construct(
        public int $regions,
        public int $departments,
        public int $communes,
        public int $newCommunes,
        public int $deactivated,
        public array $rejected,
        public int $outOfScope = 0,
    ) {
    }
}
