<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\EventHandler;

use App\QuoteRequest\Domain\Request\QuoteRequestRepository;
use App\QuoteRequest\Domain\Request\RequestStatus;
use App\Shared\Application\Bus\EventBus;
use App\Shared\Application\Bus\EventHandler;
use App\Shared\Domain\Event\AccountErased;
use App\Shared\Domain\Time\Clock;

/** RG-090 — compte client effacé : ses demandes encore actives sont annulées (les artisans sont prévenus, module Quote). */
final readonly class CancelRequestsOfErasedAccount implements EventHandler
{
    public const string REASON = 'client_account_deleted';

    public function __construct(private QuoteRequestRepository $requests, private EventBus $events, private Clock $clock)
    {
    }

    public function __invoke(AccountErased $event): void
    {
        $now = $this->clock->now();
        foreach ($this->requests->activeOfClient($event->userId) as $id) {
            $request = $this->requests->findForUpdate($id);
            if (null === $request || !$request->status()->canTransitionTo(RequestStatus::Cancelled)) {
                continue;
            }
            $request->cancel(self::REASON, $now);
            $this->events->publish(...$request->releaseEvents());
        }
    }
}
