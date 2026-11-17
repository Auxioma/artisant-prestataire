<?php

declare(strict_types=1);

namespace App\Quote\Application\Client;

use App\ArtisanProfile\Application\Quote\QuoteIssuer;
use App\ArtisanProfile\Application\Quote\QuoteIssuerView;
use App\Quote\Domain\Quote\QuoteRepository;
use App\Quote\Domain\Quote\QuoteStatus;
use App\QuoteRequest\Domain\Request\QuoteRequestId;
use App\QuoteRequest\Domain\Request\QuoteRequestRepository;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Bus\QueryHandler;

final readonly class AcceptedClientContactHandler implements QueryHandler
{
    public function __construct(private QueryBus $queries, private QuoteRepository $quotes, private QuoteRequestRepository $requests, private PartyContacts $contacts)
    {
    }

    /** @return array{name: string, email: ?string, phone: ?string, address: ?string}|null */
    public function __invoke(AcceptedClientContact $query): ?array
    {
        $issuer = $this->queries->ask(new QuoteIssuer($query->userId));
        $quote = $issuer instanceof QuoteIssuerView ? $this->quotes->findByRequestAndArtisan($query->requestId, $issuer->artisanId) : null;
        if (null === $quote || QuoteStatus::Accepted !== $quote->status()) {
            return null;
        }
        $request = $this->requests->find(new QuoteRequestId($query->requestId));

        return null === $request ? null : $this->contacts->client($request);
    }
}
