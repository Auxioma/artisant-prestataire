<?php

declare(strict_types=1);

namespace App\Messaging\Application\Query;

/** C04.1 / PR07 — ligne de la liste des conversations. */
final readonly class ConversationRow
{
    public function __construct(
        public string $id,
        public string $counterpartName,
        public ?string $counterpartLogoProfileId,
        public ?string $requestTitle,
        public ?string $requestNumber,
        public ?string $lastBody,
        public ?\DateTimeImmutable $lastAt,
        public bool $lastIsMine,
        public int $unread,
        public bool $archived,
        public bool $blocked,
    ) {
    }

    public function initials(): string
    {
        $words = preg_split('/[\s\-]+/u', trim($this->counterpartName)) ?: [];
        $letters = array_map(static fn (string $w): string => mb_strtoupper(mb_substr($w, 0, 1)), array_filter($words, static fn (string $w): bool => '' !== $w));

        return implode('', \array_slice($letters, 0, 2));
    }
}
