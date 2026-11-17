<?php

declare(strict_types=1);

namespace App\Quote\Application\EventHandler;

use App\ArtisanProfile\Application\PublicProfile\PublicArtisanProfile;
use App\ArtisanProfile\Application\PublicProfile\PublicProfileView;
use App\Catalog\Application\Query\JobIndexEntries;
use App\Catalog\Application\Query\JobIndexEntry;
use App\Quote\Application\Port\QuoteMailer;
use App\Quote\Domain\Quote\Event\QuoteReminded;
use App\Quote\Domain\Quote\Event\QuoteStatusChanged;
use App\Quote\Domain\Quote\QuoteId;
use App\Quote\Domain\Quote\QuoteRepository;
use App\Quote\Domain\Quote\QuoteStatus;
use App\QuoteRequest\Domain\Request\QuoteRequestId;
use App\QuoteRequest\Domain\Request\QuoteRequestRepository;
use App\Shared\Application\Bus\EventHandler;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Identity\UserContact;
use App\Shared\Application\Identity\UserContactDetails;
use App\Shared\Domain\Text\FrenchNumber;

/** E-12 — un devis vient d'être envoyé (ou l'artisan relance le client, P-11) : le client est prévenu (e-mail et in-app). */
final readonly class NotifyQuoteSent implements EventHandler
{
    public function __construct(private QuoteRepository $quotes, private QuoteRequestRepository $requests, private QueryBus $queries, private QuoteMailer $mailer)
    {
    }

    public function __invoke(QuoteStatusChanged|QuoteReminded $event): void
    {
        $reminder = $event instanceof QuoteReminded;
        if (!$reminder && (QuoteStatus::Sent->value !== $event->to || QuoteStatus::Draft->value !== $event->from)) {
            return;
        }
        $quote = $this->quotes->find(new QuoteId($event->quoteId));
        $request = $this->requests->find(new QuoteRequestId($event->requestId));
        $contact = null === $request ? null : $this->queries->ask(new UserContact($request->clientId()));
        if (null === $quote || null === $request || !$contact instanceof UserContactDetails || null === $quote->validUntil()) {
            return;
        }
        $artisan = $this->queries->ask(new PublicArtisanProfile($event->artisanId));
        $jobName = null;
        $jobs = $this->queries->ask(new JobIndexEntries([$request->jobId()]));
        foreach (\is_array($jobs) ? $jobs : [] as $job) {
            $jobName = $job instanceof JobIndexEntry ? $job->name : $jobName;
        }
        $this->mailer->sendNewQuote(
            $contact->email,
            $contact->firstName,
            $request->clientId(),
            $event->requestId,
            $artisan instanceof PublicProfileView ? $artisan->name : 'Un professionnel',
            $request->number(),
            $jobName,
            FrenchNumber::money($quote->current()->content->totals->ttc->amount),
            $quote->validUntil(),
            $reminder,
        );
    }
}
