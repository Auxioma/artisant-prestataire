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

    /** @return list<ReviewView> avis publiés de l'artisan (PR09, P10.9), du plus récent au plus ancien */
    public function publishedOf(string $artisanId): array;

    /**
     * PR09.1 / P10.9 — note, répartition 5→1, moyennes des sous-notes ; note des avis publiés avant `$before` (évolution).
     *
     * @return array{average: ?float, count: int, distribution: array<int, int>, criteria: array<string, float>, previous: ?float}
     */
    public function stats(string $artisanId, \DateTimeImmutable $before): array;

    /**
     * PR09.3 — prestations de l'artisan sans avis (devis accepté), avec l'état des relances.
     *
     * @return list<array{request_id: string, request_title: ?string, request_number: string, client_name: string, accepted_at: \DateTimeImmutable, service_at: ?\DateTimeImmutable, artisan_reminded_at: ?\DateTimeImmutable}>
     */
    public function solicitable(string $artisanId): array;

    /**
     * P10.9 — avis publiés triés (`recents`, `meilleurs`, `moins-bons`) et filtrés (`toutes`, `5`, `4`, `3-et-moins`).
     *
     * @return array{items: list<ReviewView>, matching: int}
     */
    public function publicPage(string $artisanId, string $sort, string $filter, int $limit): array;

    /**
     * BO05.1 — avis en attente, réponses en attente, avis publiés signalés, du plus ancien au plus récent.
     *
     * @return list<array{view: ReviewView, kind: string, quote_number: string, accepted_at: ?\DateTimeImmutable, service_at: ?\DateTimeImmutable, author_reviews: int, author_rejections: int}>
     */
    public function moderationQueue(int $limit): array;
}
