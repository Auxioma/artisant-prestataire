<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Signup;

use App\Shared\Application\Bus\Command;

/** A03.4 — mode « radius » (commune de départ + rayon) ou « list » (départements et/ou communes). */
final readonly class DefineServiceArea implements Command
{
    /**
     * @param list<string> $departments
     * @param list<string> $communes    codes INSEE
     */
    public function __construct(
        public string $userId,
        public string $mode,
        public ?string $centerInsee,
        public ?int $radiusKm,
        public array $departments = [],
        public array $communes = [],
    ) {
    }
}
