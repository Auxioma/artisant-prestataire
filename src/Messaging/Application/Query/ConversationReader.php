<?php

declare(strict_types=1);

namespace App\Messaging\Application\Query;

/** Lectures de la messagerie, toujours du point de vue d'un participant (aucun fil d'autrui). */
interface ConversationReader
{
    /**
     * @param 'all'|'unread'|'archived' $filter
     *
     * @return array{rows: list<ConversationRow>, total: int}
     */
    public function list(string $userId, string $filter, string $query, int $page, int $perPage): array;

    public function thread(string $userId, string $conversationId): ?ConversationThread;

    public function unreadCount(string $userId): int;

    /** Fil existant de l'utilisateur pour une demande et un artisan, ou une question préalable. */
    public function idFor(string $userId, ?string $requestId, ?string $artisanId): ?string;

    /** @return array{body: string, sender_first_name: string}|null */
    public function message(string $messageId): ?array;
}
