<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Verification;

final readonly class QueueItem
{
    public function __construct(
        public string $profileId,
        public string $name,
        public string $mainJob,
        public string $place,
        public \DateTimeImmutable $submittedAt,
        public bool $overdue,
        public bool $moreInfoRequested,
    ) {
    }
}
