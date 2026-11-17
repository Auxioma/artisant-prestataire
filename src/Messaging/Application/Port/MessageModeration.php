<?php

declare(strict_types=1);

namespace App\Messaging\Application\Port;

/** BO05 — file des messages signalés et décisions de la modération (`messaging.message`). */
interface MessageModeration
{
    /**
     * @return list<array{id: string, conversation_id: string, sender_name: string, sender_role: string, reporter_name: string, body: string, masked: bool, has_attachment: bool, reason: string, sent_at: \DateTimeImmutable, reported_at: \DateTimeImmutable, request_number: ?string}>
     */
    public function pending(int $limit): array;

    /**
     * Messages du fil jusqu'au message signalé inclus (les `$count` derniers).
     *
     * @return list<array{id: string, author: string, body: string, at: \DateTimeImmutable}>
     */
    public function contextOf(string $messageId, int $count): array;

    /** Original scellé d'un message signalé (null s'il n'a pas été masqué ou n'est pas signalé). */
    public function sealedOriginal(string $messageId): ?string;

    /** Décision sur un signalement en attente ; faux si le message n'est pas signalé ou déjà traité. */
    public function decide(string $messageId, string $adminId, string $decision, ?string $reason, \DateTimeImmutable $at): bool;
}
