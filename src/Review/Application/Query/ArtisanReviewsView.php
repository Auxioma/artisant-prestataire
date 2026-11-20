<?php

declare(strict_types=1);

namespace App\Review\Application\Query;

final readonly class ArtisanReviewsView
{
    /**
     * @param array{average: ?float, count: int, distribution: array<int, int>, criteria: array<string, float>, previous: ?float}                                                         $stats
     * @param list<ReviewView>                                                                                                                                                            $reviews
     * @param list<array{request_id: string, request_title: ?string, request_number: string, client_name: string, opens: \DateTimeImmutable, closes: \DateTimeImmutable, reminded: bool}> $solicit
     */
    public function __construct(public string $artisanId, public array $stats, public array $reviews, public array $solicit, public int $editDays)
    {
    }

    public function trend(): ?float
    {
        return null === $this->stats['average'] || null === $this->stats['previous'] ? null : round($this->stats['average'] - $this->stats['previous'], 1);
    }

    public function share(int $stars): int
    {
        return 0 === $this->stats['count'] ? 0 : (int) round(100 * ($this->stats['distribution'][$stars] ?? 0) / $this->stats['count']);
    }
}
