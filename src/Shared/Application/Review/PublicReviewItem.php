<?php

declare(strict_types=1);

namespace App\Shared\Application\Review;

final readonly class PublicReviewItem
{
    /** @param list<string> $photos identifiants des photos saines */
    public function __construct(
        public string $id,
        public string $author,
        public ?string $city,
        public \DateTimeImmutable $serviceDate,
        public int $rating,
        public ?string $title,
        public string $text,
        public array $photos,
        public ?string $reply,
    ) {
    }
}
