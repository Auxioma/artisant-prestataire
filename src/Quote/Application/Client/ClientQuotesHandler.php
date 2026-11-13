<?php

declare(strict_types=1);

namespace App\Quote\Application\Client;

use App\ArtisanProfile\Application\PublicProfile\PublicArtisanProfile;
use App\ArtisanProfile\Application\PublicProfile\PublicCredential;
use App\ArtisanProfile\Application\PublicProfile\PublicProfileView;
use App\Catalog\Application\Geo\Query\CommunesByInsee;
use App\Catalog\Application\Geo\Query\CommuneSummary;
use App\Quote\Application\Pdf\QuotePdfService;
use App\Quote\Domain\Quote\Quote;
use App\Quote\Domain\Quote\QuoteId;
use App\Quote\Domain\Quote\QuoteRepository;
use App\Quote\Domain\Quote\QuoteStatus;
use App\QuoteRequest\Domain\Request\QuoteRequest;
use App\QuoteRequest\Domain\Request\QuoteRequestId;
use App\QuoteRequest\Domain\Request\QuoteRequestRepository;
use App\QuoteRequest\Domain\Request\RequestStatus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Domain\Time\Clock;
use App\Shared\Domain\ValueObject\Money;

/**
 * C03 — devis reçus par le client sur sa demande (jamais ceux d'une autre) : cartes et comparatif, PDF figé. Un devis
 * est « à décider » s'il est envoyé ou consulté, encore valable, et si la demande n'est pas attribuée ni close.
 */
final readonly class ClientQuotesHandler implements QueryHandler
{
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    private const int MAIN_LINES = 3;

    public function __construct(private QuoteRepository $quotes, private QuoteRequestRepository $requests, private QuotePdfService $pdf, private QueryBus $queries, private Clock $clock, private PartyContacts $contacts)
    {
    }

    public function __invoke(ClientRequestQuotes|ClientQuotePdf $query): ClientQuotes|string|null
    {
        if ($query instanceof ClientQuotePdf) {
            $quote = 1 === preg_match(self::UUID, $query->quoteId) ? $this->quotes->find(new QuoteId($query->quoteId)) : null;
            $pdf = null !== $quote && null !== $this->ownedRequest($query->userId, $quote->requestId()) && self::visible($quote) ? $quote->current()->pdf : null;

            return null === $pdf ? null : $this->pdf->read($pdf);
        }
        $request = $this->ownedRequest($query->userId, $query->requestId);
        if (null === $request) {
            return null;
        }
        $now = $this->clock->now();
        $open = \in_array($request->status(), [RequestStatus::Open, RequestStatus::Full], true);
        $cards = [];
        $accepted = null;
        foreach ($this->quotes->sentForRequest($query->requestId) as $quote) {
            $accepted = QuoteStatus::Accepted === $quote->status() ? $quote->id()->value : $accepted;
            $cards[] = $this->card($quote, $open && $quote->status()->isRevisable() && null !== $quote->validUntil() && $now < $quote->validUntil());
        }

        $communes = $this->queries->ask(new CommunesByInsee([$request->cityInsee()]));
        $commune = \is_array($communes) ? ($communes[0] ?? null) : null;
        $city = ($commune instanceof CommuneSummary ? $commune->name.' ' : '').'('.$request->postalCode().')';

        return new ClientQuotes($cards, $accepted, $query->requestId, $request->number(), $request->details()->title, $request->maxQuotes(), $open, $city, null === $accepted ? null : $this->contacts->artisan($this->acceptedArtisan($cards, $accepted)));
    }

    /** @param list<ClientQuoteCard> $cards */
    private function acceptedArtisan(array $cards, string $quoteId): string
    {
        foreach ($cards as $card) {
            if ($card->quoteId === $quoteId) {
                return $card->artisanId;
            }
        }

        return '';
    }

    /** Demande du client connecté (null si elle n'existe pas ou appartient à un autre). */
    public function ownedRequest(string $userId, string $requestId): ?QuoteRequest
    {
        $request = 1 === preg_match(self::UUID, $requestId) ? $this->requests->find(new QuoteRequestId($requestId)) : null;

        return null !== $request && $request->clientId() === $userId ? $request : null;
    }

    public static function visible(Quote $quote): bool
    {
        return !\in_array($quote->status(), [QuoteStatus::Draft, QuoteStatus::Withdrawn], true);
    }

    /**
     * Taux de TVA réellement appliqués (base HT non nulle), croissants.
     *
     * @param array<int, array{ht: Money, vat: Money}> $byRate
     *
     * @return list<int>
     */
    private static function rates(array $byRate): array
    {
        $rates = [];
        foreach ($byRate as $rate => $amounts) {
            if ($amounts['ht']->amount > 0) {
                $rates[] = (int) $rate;
            }
        }
        sort($rates);

        return $rates;
    }

    private function card(Quote $quote, bool $decidable): ClientQuoteCard
    {
        $content = $quote->current()->content;
        $totals = $content->totals;
        $artisan = $this->queries->ask(new PublicArtisanProfile($quote->artisanId()));
        $lines = [];
        $priced = 0;
        foreach ($content->lines as $line) {
            if ($line->type->isPriced()) {
                ++$priced;
                if (\count($lines) < self::MAIN_LINES) {
                    $lines[] = $line->label;
                }
            }
        }

        return new ClientQuoteCard(
            $quote->id()->value,
            $quote->number() ?? '',
            $quote->status(),
            $quote->current()->number,
            $quote->artisanId(),
            $artisan instanceof PublicProfileView ? $artisan->name : 'Professionnel',
            $artisan instanceof PublicProfileView ? $artisan->communeName : null,
            $totals->ttc->amount,
            $totals->ht->amount,
            $totals->vat->amount,
            self::rates($totals->byRate),
            $content->vatExempt,
            $content->deposit?->percentBp,
            $totals->deposit?->amount,
            $quote->validUntil(),
            $content->conditions->executionDelay,
            $content->conditions->guarantees,
            $lines,
            $quote->current()->sentAt,
            $decidable,
            $quote->decisionReason(),
            $priced,
            $artisan instanceof PublicProfileView ? array_values(array_map(static fn (PublicCredential $c): string => $c->label, array_filter($artisan->qualifications, static fn (PublicCredential $c): bool => $c->verified))) : [],
        );
    }
}
