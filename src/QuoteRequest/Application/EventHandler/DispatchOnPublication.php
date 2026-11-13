<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\EventHandler;

use App\QuoteRequest\Application\Dispatch\DispatchQuoteRequest;
use App\QuoteRequest\Domain\Request\Event\QuoteRequestStatusChanged;
use App\QuoteRequest\Domain\Request\Event\QuoteRequestSubmitted;
use App\QuoteRequest\Domain\Request\RequestStatus;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\EventHandler;

/** RG-062 — une demande devient ouverte (envoi sans modération, validation BO04, réouverture) : elle est diffusée. */
final readonly class DispatchOnPublication implements EventHandler
{
    public function __construct(private CommandBus $commands)
    {
    }

    public function __invoke(QuoteRequestSubmitted|QuoteRequestStatusChanged $event): void
    {
        $opened = $event instanceof QuoteRequestSubmitted ? $event->status : $event->to;
        if (RequestStatus::Open->value === $opened) {
            $this->commands->dispatch(new DispatchQuoteRequest($event->requestId));
        }
    }
}
