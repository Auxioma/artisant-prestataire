<?php

declare(strict_types=1);

namespace App\Shared\Application\Moderation;

/** Message signalé, avec les messages qui le précèdent dans le fil (contexte de la décision). */
final readonly class ReportedMessageItem
{
    /** @param list<array{author: string, body: string, at: \DateTimeImmutable, reported: bool}> $context du plus ancien au plus récent, message signalé compris */
    public function __construct(
        public string $id,
        public string $conversationId,
        public string $senderName,
        public string $senderRole,
        public string $reporterName,
        public string $body,
        public bool $masked,
        public bool $hasAttachment,
        public string $reason,
        public string $reasonLabel,
        public \DateTimeImmutable $sentAt,
        public \DateTimeImmutable $reportedAt,
        public ?string $requestNumber,
        public array $context,
    ) {
    }
}
