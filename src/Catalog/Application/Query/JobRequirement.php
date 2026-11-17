<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

final readonly class JobRequirement
{
    /** @param list<string> $requiredDocuments codes (kbis, insurance_rc, insurance_decennial, rge, electrical_clearance…) */
    public function __construct(
        public string $jobId,
        public string $name,
        public array $requiredDocuments,
        public bool $insuranceRequired,
        public bool $decennialRequired,
    ) {
    }
}
