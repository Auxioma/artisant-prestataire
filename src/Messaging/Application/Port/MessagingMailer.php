<?php

declare(strict_types=1);

namespace App\Messaging\Application\Port;

interface MessagingMailer
{
    /** E-17 — nouveau message (regroupé) : extrait du texte affiché (masqué le cas échéant), lien vers le fil. */
    public function newMessage(string $recipientUserId, bool $recipientIsArtisan, string $senderName, string $excerpt, string $conversationId): void;
}
