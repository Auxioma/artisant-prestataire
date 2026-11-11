<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Domain\Profile\Event;

use App\Shared\Domain\Event\DomainEvent;

/** Entreprise déclarée (A03.2) ; non vérifiée = Sirene indisponible, contrôle différé. */
final readonly class ArtisanCompanyDeclared implements DomainEvent
{
    public function __construct(public string $profileId, public bool $verified, public \DateTimeImmutable $at)
    {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
