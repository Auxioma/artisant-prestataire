<?php

declare(strict_types=1);

namespace App\Messaging\Application\Port;

/** État de chaque participant d'une conversation (`messaging.participant`). */
interface ParticipantStates
{
    public function ensure(string $conversationId, string $userId): void;

    public function markRead(string $conversationId, string $userId, \DateTimeImmutable $at): void;

    public function archive(string $conversationId, string $userId, bool $archived): void;

    /**
     * Regroupement E-17 : réserve l'envoi si aucun e-mail n'est parti pour ce fil depuis `$notSince` et si le destinataire
     * n'a pas lu le fil depuis le message (il est alors en ligne). Vrai : envoyer.
     */
    public function claimNotification(string $conversationId, string $userId, \DateTimeImmutable $messageAt, \DateTimeImmutable $notSince, \DateTimeImmutable $at): bool;

    /** PR07 — note privée du participant ; faux s'il ne participe pas à la conversation. */
    public function saveNote(string $conversationId, string $userId, ?string $note): bool;

    /** Signalement d'un message par un participant (une fois). */
    public function report(string $messageId, string $userId, string $reason, \DateTimeImmutable $at): bool;

    /** BO05.3 — signalement automatique (score de risque au-delà du seuil), sans signalant. */
    public function flag(string $messageId, string $reason, \DateTimeImmutable $at): void;
}
