<?php

declare(strict_types=1);

namespace App\Notification\Application\Port;

interface InAppNotifications
{
    public function add(string $id, string $userId, string $code, string $body, ?string $link, \DateTimeImmutable $at): void;

    public function unreadCount(string $userId): int;

    /**
     * Notifications de l'utilisateur, les plus récentes d'abord : codes inclus (`$codes`, null = tous) ou exclus.
     *
     * @param list<string>|null $codes
     * @param list<string>      $excluded
     *
     * @return array{rows: list<array{id: string, code: string, body: string, link: ?string, at: \DateTimeImmutable, read: bool}>, total: int}
     */
    public function page(string $userId, ?array $codes, array $excluded, int $limit, int $offset): array;

    /** Marque lue une notification (ou toutes si `$id` est null). */
    public function markRead(string $userId, ?string $id, \DateTimeImmutable $at): void;

    /** Lien d'une notification de l'utilisateur, ou null. */
    public function link(string $userId, string $id): ?string;

    public function purgeBefore(\DateTimeImmutable $before): int;
}
