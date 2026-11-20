<?php

declare(strict_types=1);

namespace App\Shared\Application\Moderation;

final readonly class ProfileReviewItem
{
    /** @param list<array{label: string, value: string}> $lines contenu relu, en lignes lisibles */
    public function __construct(
        public string $id,
        public string $artisanId,
        public string $artisanName,
        public string $section,
        public string $sectionLabel,
        public array $lines,
        public bool $hasPhoto,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
