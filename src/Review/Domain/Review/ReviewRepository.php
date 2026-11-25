<?php

declare(strict_types=1);

namespace App\Review\Domain\Review;

interface ReviewRepository
{
    public function findForUpdate(ReviewId $id): ?Review;

    public function existsForRequest(string $requestId): bool;

    public function save(Review $review): void;

    public function delete(ReviewId $id): void;
}
