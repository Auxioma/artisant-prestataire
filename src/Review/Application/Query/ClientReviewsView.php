<?php

declare(strict_types=1);

namespace App\Review\Application\Query;

final readonly class ClientReviewsView
{
    /**
     * @param list<array{request_id: string, request_number: string, request_title: ?string, artisan_id: string, artisan_name: string, logo: ?string, opens: \DateTimeImmutable, closes: \DateTimeImmutable, open: bool}> $toGive
     * @param list<ReviewView>                                                                                                                                                                                            $mine
     */
    public function __construct(public array $toGive, public array $mine, public string $moderationMode, public int $editDays)
    {
    }

    /** @return array{request_id: string, request_number: string, request_title: ?string, artisan_id: string, artisan_name: string, logo: ?string, opens: \DateTimeImmutable, closes: \DateTimeImmutable, open: bool}|null */
    public function eligible(string $requestId): ?array
    {
        foreach ($this->toGive as $item) {
            if ($item['request_id'] === $requestId && $item['open']) {
                return $item;
            }
        }

        return null;
    }

    public function mine(string $reviewId): ?ReviewView
    {
        foreach ($this->mine as $review) {
            if ($review->id === $reviewId) {
                return $review;
            }
        }

        return null;
    }
}
