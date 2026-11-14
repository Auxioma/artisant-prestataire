<?php

declare(strict_types=1);

namespace App\Messaging\Application\Query;

/** Lectures de la messagerie, toujours du point de vue d'un participant (aucun fil d'autrui). */
interface ConversationReader
{
    /**
     * @param 'all'|'unread'|'clients'|'prospects'|'archived' $filter
     *
     * @return array{rows: list<ConversationRow>, total: int}
     */
    public function list(string $userId, string $filter, string $query, int $page, int $perPage): array;

    public function thread(string $userId, string $conversationId): ?ConversationThread;

    /**
     * Compteurs des onglets de PR07 : non archivées, non lues, clients (devis de l'artisan accepté), prospects, archivées.
     *
     * @return array{all: int, unread: int, clients: int, prospects: int, archived: int}
     */
    public function counts(string $userId): array;

    /** Délai moyen (minutes) entre le premier message d'un client et la réponse de l'artisan, ou null (échantillon trop petit). */
    public function averageResponseMinutes(string $artisanId, \DateTimeImmutable $since, int $minSamples): ?int;

    public function unreadCount(string $userId): int;

    /** Fil existant de l'utilisateur pour une demande et un artisan, ou une question préalable. */
    public function idFor(string $userId, ?string $requestId, ?string $artisanId): ?string;

    /** @return array{body: string, sender_first_name: string}|null */
    public function message(string $messageId): ?array;
}
