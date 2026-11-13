<?php

declare(strict_types=1);

namespace App\Quote\Application\Client;

final readonly class ClientQuotes
{
    /** @param list<ClientQuoteCard> $cards */
    public function __construct(public array $cards, public ?string $acceptedQuoteId)
    {
    }

    /** Devis encore à décider (envoyés ou consultés, valables) : comparables et acceptables. */
    public function decidable(): int
    {
        return \count(array_filter($this->cards, static fn (ClientQuoteCard $c): bool => $c->decidable));
    }

    /** C03.4 — carte au prix TTC le plus bas parmi les devis à décider (au moins deux). */
    public function cheapestId(): ?string
    {
        $open = array_values(array_filter($this->cards, static fn (ClientQuoteCard $c): bool => $c->decidable));
        if (\count($open) < 2) {
            return null;
        }
        usort($open, static fn (ClientQuoteCard $a, ClientQuoteCard $b): int => $a->ttcCents <=> $b->ttcCents);

        return $open[0]->quoteId;
    }
}
