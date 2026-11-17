<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Agenda;

use App\ArtisanProfile\Application\Port\AgendaStore;
use App\ArtisanProfile\Domain\Agenda\Absence;
use App\Shared\Application\Bus\QueryHandler;

final readonly class LeaveQueriesHandler implements QueryHandler
{
    public function __construct(private AgendaStore $store)
    {
    }

    /** @return list<Absence>|null */
    public function __invoke(ArtisanAbsences $query): ?array
    {
        $artisanId = $this->store->artisanOfUser($query->userId);

        return null === $artisanId ? null : $this->store->absences($artisanId);
    }
}
