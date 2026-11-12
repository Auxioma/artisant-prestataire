<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\EventHandler;

use App\Catalog\Application\Geo\Query\CommunesByInsee;
use App\Catalog\Application\Geo\Query\CommuneSummary;
use App\Catalog\Application\Query\JobIndexEntries;
use App\Catalog\Application\Query\JobIndexEntry;
use App\QuoteRequest\Application\Port\RequestMailer;
use App\QuoteRequest\Domain\Request\Event\QuoteRequestSubmitted;
use App\QuoteRequest\Domain\Request\QuoteRequestId;
use App\QuoteRequest\Domain\Request\QuoteRequestRepository;
use App\QuoteRequest\Domain\Request\RequestStatus;
use App\Shared\Application\Bus\EventHandler;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Identity\UserContact;
use App\Shared\Application\Identity\UserContactDetails;

/** E-10 — confirmation au client d'une demande publiée (une demande mise en modération reçoit E-10b, T-403). */
final readonly class SendRequestSubmitted implements EventHandler
{
    public function __construct(private QuoteRequestRepository $requests, private QueryBus $queries, private RequestMailer $mailer)
    {
    }

    public function __invoke(QuoteRequestSubmitted $event): void
    {
        if (RequestStatus::Open->value !== $event->status) {
            return;
        }
        $request = $this->requests->find(new QuoteRequestId($event->requestId));
        $contact = $this->queries->ask(new UserContact($event->clientId));
        if (null === $request || !$contact instanceof UserContactDetails) {
            return;
        }
        $jobName = '';
        $jobs = $this->queries->ask(new JobIndexEntries([$event->jobId]));
        foreach (\is_array($jobs) ? $jobs : [] as $job) {
            $jobName = $job instanceof JobIndexEntry ? $job->name : $jobName;
        }
        $city = $request->postalCode();
        $communes = $this->queries->ask(new CommunesByInsee([$event->cityInsee]));
        foreach (\is_array($communes) ? $communes : [] as $commune) {
            $city = $commune instanceof CommuneSummary ? $commune->name.' ('.$request->postalCode().')' : $city;
        }
        $this->mailer->sendSubmitted($contact->email, $contact->firstName, $event->clientId, $event->requestId, $request->number(), $jobName, $city, $request->maxQuotes());
    }
}
