<?php

declare(strict_types=1);

namespace App\Messaging\Domain\Conversation;

/**
 * Message posté (immuable) : texte affiché (masqué si des coordonnées ont été détectées avant acceptation, RG-080) et,
 * dans ce cas seulement, original scellé lisible par la modération (ADR-0014).
 */
final readonly class Message
{
    public const int MAX_LENGTH = 5000;

    public function __construct(
        public string $id,
        public string $senderUserId,
        public string $body,
        public ?string $sealedOriginal,
        public \DateTimeImmutable $at,
    ) {
        if ('' === trim($body)) {
            throw new MessagingRuleViolation('Le message est vide.');
        }
        if (mb_strlen($body) > self::MAX_LENGTH) {
            throw new MessagingRuleViolation('Le message est trop long (5 000 caractères au plus).');
        }
    }

    public function masked(): bool
    {
        return null !== $this->sealedOriginal;
    }
}
