<?php

declare(strict_types=1);

namespace App\Review\Application\Review;

use App\Shared\Application\Bus\Command;

/** C06.2 — dépôt (ou modification si `$reviewId` existe déjà pour ce client) d'un avis certifié sur une demande. */
final readonly class SubmitReview implements Command
{
    /**
     * @param array<string, int> $subRatings
     * @param list<string>       $photos
     */
    public function __construct(
        public string $userId,
        public string $reviewId,
        public string $requestId,
        public int $rating,
        public array $subRatings,
        public ?string $title,
        public string $text,
        public ?string $serviceDate,
        public ?bool $recommends,
        public array $photos = [],
    ) {
    }
}
