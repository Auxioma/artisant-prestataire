<?php

declare(strict_types=1);

namespace App\Review\Application\Query;

interface ReviewReader
{
    /**
     * C06.1 — demandes du client avec un devis accepté et sans avis (fenêtre calculée par l'appelant).
     *
     * @return list<array{request_id: string, request_number: string, request_title: ?string, artisan_id: string, artisan_name: string, logo: ?string, accepted_at: \DateTimeImmutable, service_at: ?\DateTimeImmutable}>
     */
    public function reviewable(string $clientUserId): array;

    /** @return list<ReviewView> avis du client, du plus récent au plus ancien */
    public function ofClient(string $clientUserId): array;

    public function one(string $reviewId): ?ReviewView;
}
