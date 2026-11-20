<?php

declare(strict_types=1);

namespace App\Search\Application\Port;

final readonly class PlaceDocument
{
    /**
     * @param list<string> $postalCodes
     */
    public function __construct(
        public string $insee,
        public string $name,
        public string $slug,
        public array $postalCodes,
        public string $departmentCode,
        public string $departmentName,
        public int $population,
    ) {
    }
}
