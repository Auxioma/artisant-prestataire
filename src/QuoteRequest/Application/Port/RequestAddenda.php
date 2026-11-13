<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Port;

/** `request.request_addendum` — compléments d'une demande, du plus ancien au plus récent. */
interface RequestAddenda
{
    public function add(string $id, string $requestId, string $body, \DateTimeImmutable $at): void;

    public function count(string $requestId): int;

    /** @return list<array{body: string, createdAt: \DateTimeImmutable}> */
    public function of(string $requestId): array;
}
