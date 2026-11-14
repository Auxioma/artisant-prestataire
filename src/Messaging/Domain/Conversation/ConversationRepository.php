<?php

declare(strict_types=1);

namespace App\Messaging\Domain\Conversation;

interface ConversationRepository
{
    public function find(ConversationId $id): ?Conversation;

    /** Verrouille la conversation (envois concurrents sérialisés). */
    public function findForUpdate(ConversationId $id): ?Conversation;

    public function findForRequest(string $requestId, string $artisanId): ?Conversation;

    public function findPreQuestion(string $clientUserId, string $artisanId): ?Conversation;

    /** Purge (C04) : conversations sans activité depuis `$before`, messages compris ; renvoie leur nombre. */
    /**
     * Fichiers joints des conversations qui seront purgées avant `$before` (à détruire avant la purge).
     *
     * @return list<string>
     */
    public function attachmentsInactiveBefore(\DateTimeImmutable $before): array;

    public function purgeInactiveBefore(\DateTimeImmutable $before): int;

    /** Enregistre la conversation et les messages postés pendant l'opération. */
    public function save(Conversation $conversation): void;
}
