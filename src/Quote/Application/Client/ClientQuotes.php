<?php

declare(strict_types=1);

namespace App\Quote\Application\Client;

final readonly class ClientQuotes
{
    /** @param list<ClientQuoteCard> $cards */
    public function __construct(
        public array $cards,
        public ?string $acceptedQuoteId,
        public string $requestId = '',
        public string $requestNumber = '',
        public ?string $requestTitle = null,
        public int $maxQuotes = 0,
        public bool $requestOpen = false,
        public ?string $requestCity = null,
        /** @var array{name: string, email: ?string, phone: ?string}|null coordonnées de l'artisan retenu (RG-022bis) */
        public ?array $acceptedContact = null,
    ) {
    }

    public function accepted(): ?ClientQuoteCard
    {
        foreach ($this->cards as $card) {
            if ($card->quoteId === $this->acceptedQuoteId) {
                return $card;
            }
        }

        return null;
    }

    /** C03.3 — « Réponse détaillée » : le devis à décider qui détaille le plus de postes (s'il se distingue). */
    public function mostDetailedId(): ?string
    {
        $open = array_values(array_filter($this->cards, static fn (ClientQuoteCard $c): bool => $c->decidable));
        if (\count($open) < 2) {
            return null;
        }
        usort($open, static fn (ClientQuoteCard $a, ClientQuoteCard $b): int => $b->pricedLines <=> $a->pricedLines);

        return $open[0]->pricedLines > $open[1]->pricedLines ? $open[0]->quoteId : null;
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
