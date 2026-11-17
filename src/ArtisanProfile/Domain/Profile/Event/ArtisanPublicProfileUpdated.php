<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Domain\Profile\Event;

use App\Shared\Domain\Event\DomainEvent;

/** PR08 — contenu public modifié par l'artisan : complétude recalculée, projection de recherche mise à jour. */
final readonly class ArtisanPublicProfileUpdated implements DomainEvent
{
    public function __construct(public string $profileId, public string $userId, public \DateTimeImmutable $at)
    {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
