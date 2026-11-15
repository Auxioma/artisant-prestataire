<?php

declare(strict_types=1);

namespace App\Review\Application\Port;

/** E-18 (au client : avis publié, refusé, masqué, modification demandée), E-37 (nouvel avis), E-38 (réponse publiée / refusée). */
interface ReviewMailer
{
    public function reviewDecision(string $email, ?string $firstName, string $userId, string $decision, string $artisanName, ?string $reason): void;

    public function newReview(string $email, ?string $firstName, string $userId, int $rating, string $requestNumber): void;

    public function replyDecision(string $userId, bool $published, ?string $reason): void;
}
