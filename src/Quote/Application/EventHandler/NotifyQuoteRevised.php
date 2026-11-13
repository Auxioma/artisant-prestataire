<?php

declare(strict_types=1);

namespace App\Quote\Application\EventHandler;

use App\ArtisanProfile\Application\PublicProfile\PublicArtisanProfile;
use App\ArtisanProfile\Application\PublicProfile\PublicProfileView;
use App\Quote\Application\Port\QuoteMailer;
use App\Quote\Domain\Quote\Event\QuoteRevised;
use App\Quote\Domain\Quote\QuoteId;
use App\Quote\Domain\Quote\QuoteRepository;
use App\QuoteRequest\Domain\Request\QuoteRequestId;
use App\QuoteRequest\Domain\Request\QuoteRequestRepository;
use App\Shared\Application\Bus\EventHandler;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Identity\UserContact;
use App\Shared\Application\Identity\UserContactDetails;
use App\Shared\Domain\Text\FrenchNumber;

/** E-12b — l'artisan a révisé son devis (RG-102) : le client est prévenu (e-mail et in-app). */
final readonly class NotifyQuoteRevised implements EventHandler
{
    public function __construct(private QuoteRepository $quotes, private QuoteRequestRepository $requests, private QueryBus $queries, private QuoteMailer $mailer)
    {
    }

    public function __invoke(QuoteRevised $event): void
    {
        $quote = $this->quotes->find(new QuoteId($event->quoteId));
        $request = $this->requests->find(new QuoteRequestId($event->requestId));
        $contact = null === $request ? null : $this->queries->ask(new UserContact($request->clientId()));
        if (null === $quote || null === $request || !$contact instanceof UserContactDetails || null === $quote->validUntil()) {
            return;
        }
        $version = $quote->version($event->version) ?? $quote->current();
        $artisan = $this->queries->ask(new PublicArtisanProfile($event->artisanId));
        $this->mailer->sendRevisedQuote(
            $contact->email,
            $contact->firstName,
            $request->clientId(),
            $event->requestId,
            $artisan instanceof PublicProfileView ? $artisan->name : 'Un professionnel',
            $request->number(),
            $event->version,
            FrenchNumber::money($version->content->totals->ttc->amount),
            $quote->validUntil(),
        );
    }
}
