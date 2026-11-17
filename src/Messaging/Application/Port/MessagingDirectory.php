<?php

declare(strict_types=1);

namespace App\Messaging\Application\Port;

/** Lectures des autres modules nécessaires à la messagerie (demandes, artisans, devis), sans donnée inutile. */
interface MessagingDirectory
{
    /** @return array{client_user_id: string, number: string, title: string, city: string}|null */
    public function request(string $requestId): ?array;

    /** @return array{artisan_id: string, user_id: string, name: string, public: bool}|null */
    public function artisanOfUser(string $userId): ?array;

    /** @return array{artisan_id: string, user_id: string, name: string, public: bool}|null */
    public function artisan(string $artisanId): ?array;

    /** L'artisan a-t-il envoyé un devis sur la demande ? (le client peut alors lui écrire) */
    public function hasSentQuote(string $requestId, string $artisanId): bool;

    /** Un devis de l'artisan a-t-il été accepté sur la demande ? (RG-080 : coordonnées alors visibles) */
    public function quoteAccepted(string $requestId, string $artisanId): bool;

    /** Questions préalables lancées par le client depuis `$since` (Q14, plafond quotidien). */
    public function preQuestionsSince(string $clientUserId, \DateTimeImmutable $since): int;
}
