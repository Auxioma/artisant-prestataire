<?php

declare(strict_types=1);

namespace App\Shared\Application\Review;

final readonly class PublicReviewsView
{
    /**
     * @param array<int, int>        $distribution étoiles => nombre d'avis
     * @param array<string, float>   $criteria     sous-note => moyenne
     * @param list<PublicReviewItem> $items
     */
    public function __construct(
        public ?float $average,
        public int $count,
        public array $distribution,
        public array $criteria,
        public array $items,
        public int $matching,
    ) {
    }

    public function share(int $stars): int
    {
        return 0 === $this->count ? 0 : (int) round(100 * ($this->distribution[$stars] ?? 0) / $this->count);
    }
}
