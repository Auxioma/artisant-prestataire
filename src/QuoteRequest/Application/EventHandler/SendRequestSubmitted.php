<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\EventHandler;

use App\Catalog\Application\Geo\Query\CommunesByInsee;
use App\Catalog\Application\Geo\Query\CommuneSummary;
use App\Catalog\Application\Query\JobIndexEntries;
use App\Catalog\Application\Query\JobIndexEntry;
use App\QuoteRequest\Application\Port\RequestMailer;
use App\QuoteRequest\Domain\Request\Event\QuoteRequestStatusChanged;
use App\QuoteRequest\Domain\Request\Event\QuoteRequestSubmitted;
use App\QuoteRequest\Domain\Request\QuoteRequestId;
use App\QuoteRequest\Domain\Request\QuoteRequestRepository;
use App\QuoteRequest\Domain\Request\RejectionReason;
use App\QuoteRequest\Domain\Request\RequestStatus;
use App\Shared\Application\Bus\EventHandler;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Identity\UserContact;
use App\Shared\Application\Identity\UserContactDetails;

/**
 * E-10 — demande publiée (à l'envoi, ou à sa validation par la modération) ; E-10b — demande mise en modération
 * (RG-063), puis refusée (motif normalisé, BO04.1).
 */
final readonly class SendRequestSubmitted implements EventHandler
{
    private const string APPROVED = 'moderation_approved';

    public function __construct(private QuoteRequestRepository $requests, private QueryBus $queries, private RequestMailer $mailer)
    {
    }

    public function __invoke(QuoteRequestSubmitted|QuoteRequestStatusChanged $event): void
    {
        if ($event instanceof QuoteRequestStatusChanged && RequestStatus::PendingModeration->value !== $event->from) {
            return; // seules les décisions de modération sont notifiées ici
        }
        $request = $this->requests->find(new QuoteRequestId($event->requestId));
        $contact = $this->queries->ask(new UserContact($event->clientId));
        if (null === $request || !$contact instanceof UserContactDetails) {
            return;
        }
        $published = $event instanceof QuoteRequestSubmitted
            ? RequestStatus::Open->value === $event->status
            : RequestStatus::Open->value === $event->to && self::APPROVED === $event->reason;
        $jobName = $this->jobName($request->jobId());
        if ($published) {
            $this->mailer->sendSubmitted($contact->email, $contact->firstName, $event->clientId, $event->requestId, $request->number(), $jobName, $this->city($request->cityInsee(), $request->postalCode()), $request->maxQuotes());
        } elseif ($event instanceof QuoteRequestSubmitted && RequestStatus::PendingModeration->value === $event->status) {
            $this->mailer->sendModeration($contact->email, $contact->firstName, $event->clientId, $request->number(), $jobName, null);
        } elseif ($event instanceof QuoteRequestStatusChanged && RequestStatus::Rejected->value === $event->to) {
            $this->mailer->sendModeration($contact->email, $contact->firstName, $event->clientId, $request->number(), $jobName, RejectionReason::tryFrom($event->reason)?->label() ?? $event->reason);
        }
    }

    private function jobName(string $jobId): string
    {
        $jobName = '';
        $jobs = $this->queries->ask(new JobIndexEntries([$jobId]));
        foreach (\is_array($jobs) ? $jobs : [] as $job) {
            $jobName = $job instanceof JobIndexEntry ? $job->name : $jobName;
        }

        return $jobName;
    }

    private function city(string $insee, string $postalCode): string
    {
        $city = $postalCode;
        $communes = $this->queries->ask(new CommunesByInsee([$insee]));
        foreach (\is_array($communes) ? $communes : [] as $commune) {
            $city = $commune instanceof CommuneSummary ? $commune->name.' ('.$postalCode.')' : $city;
        }

        return $city;
    }
}
