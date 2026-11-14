<?php

declare(strict_types=1);

namespace App\Messaging\Application\Query;

/** C04.2 — fil : interlocuteur, carte de contexte (demande, devis), messages, état (archivé, bloqué). */
final readonly class ConversationThread
{
    /** @param list<ThreadMessage> $messages du plus ancien au plus récent */
    public function __construct(
        public string $id,
        public string $kind,
        public string $counterpartName,
        public ?string $counterpartLogoProfileId,
        public ?string $counterpartCity,
        public ?string $artisanId,
        public ?string $requestId,
        public ?string $requestTitle,
        public ?string $requestNumber,
        public ?string $quoteNumber,
        public ?string $quoteStatus,
        public bool $contactsShared,
        public bool $archived,
        public ?string $blocked,
        public array $messages,
    ) {
    }

    /** Peut-on écrire ? (fil non bloqué) */
    public function writable(): bool
    {
        return null === $this->blocked;
    }
}
