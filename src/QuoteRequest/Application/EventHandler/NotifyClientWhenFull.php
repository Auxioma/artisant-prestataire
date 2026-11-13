<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\EventHandler;

use App\QuoteRequest\Application\Port\RequestMailer;
use App\QuoteRequest\Domain\Request\Event\QuoteRequestStatusChanged;
use App\QuoteRequest\Domain\Request\QuoteRequestId;
use App\QuoteRequest\Domain\Request\QuoteRequestRepository;
use App\QuoteRequest\Domain\Request\RequestStatus;
use App\Shared\Application\Bus\EventHandler;

/** E-19b — la demande a atteint son nombre maximum de devis (RG-070) : le client est prévenu dans son espace. */
final readonly class NotifyClientWhenFull implements EventHandler
{
    public function __construct(private QuoteRequestRepository $requests, private RequestMailer $mailer)
    {
    }

    public function __invoke(QuoteRequestStatusChanged $event): void
    {
        if (RequestStatus::Full->value !== $event->to) {
            return;
        }
        $request = $this->requests->find(new QuoteRequestId($event->requestId));
        if (null !== $request) {
            $this->mailer->notifyRequestFull($event->clientId, $event->requestId, $request->number(), $request->maxQuotes());
        }
    }
}
