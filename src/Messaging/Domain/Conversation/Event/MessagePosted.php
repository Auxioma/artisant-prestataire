<?php

declare(strict_types=1);

namespace App\Messaging\Domain\Conversation\Event;

use App\Shared\Domain\Event\DomainEvent;

/** Nouveau message : E-17 au destinataire (regroupé, notifications.message_email_throttle_min). */
final readonly class MessagePosted implements DomainEvent
{
    public function __construct(
        public string $conversationId,
        public string $messageId,
        public string $senderUserId,
        public string $recipientUserId,
        public \DateTimeImmutable $at,
    ) {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
