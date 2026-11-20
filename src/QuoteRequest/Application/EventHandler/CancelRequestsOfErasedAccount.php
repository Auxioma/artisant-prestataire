<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\EventHandler;

use App\QuoteRequest\Application\Retention\RequestArchive;
use App\QuoteRequest\Domain\Request\QuoteRequestRepository;
use App\QuoteRequest\Domain\Request\RequestStatus;
use App\Shared\Application\Bus\EventBus;
use App\Shared\Application\Bus\EventHandler;
use App\Shared\Application\Storage\FileShredder;
use App\Shared\Domain\Event\AccountErased;
use App\Shared\Domain\Time\Clock;

/** RG-090 — compte client effacé : demandes actives annulées (artisans prévenus, module Quote), puis toutes archivées. */
final readonly class CancelRequestsOfErasedAccount implements EventHandler
{
    public const string REASON = 'client_account_deleted';

    public function __construct(private QuoteRequestRepository $requests, private RequestArchive $archive, private FileShredder $shredder, private EventBus $events, private Clock $clock)
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
        // T-808 : textes libres, adresse et pièces jointes de toutes ses demandes effacés (numéros et historique conservés).
        foreach ($this->archive->inactiveOfClient($event->userId) as $id) {
            foreach ($this->archive->archive($id, $now) as $file) {
                $this->shredder->shred($file, 'account_erased');
            }
        }
    }
}
