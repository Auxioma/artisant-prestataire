<?php

declare(strict_types=1);

namespace App\Search\Application\Favorite;

final readonly class FavoriteView
{
    /** @param list<string> $jobIds */
    public function __construct(
        public string $artisanId,
        public string $name,
        public bool $active,
        public ?string $communeName,
        public ?string $departmentCode,
        public array $jobIds,
        public ?float $rating,
        public int $reviewsCount,
        public bool $available,
        public bool $hasLogo,
        public \DateTimeImmutable $addedAt,
    ) {
    }
}
