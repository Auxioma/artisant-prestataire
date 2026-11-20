<?php

declare(strict_types=1);

namespace App\Shared\Domain\Event\Integration;

use App\Shared\Domain\Event\DomainEvent;

/**
 * Événement d'intégration : compte professionnel vérifié (RG-043). Publié par ArtisanProfile, consommé par les modules
 * qui n'en dépendent pas (Billing : crédit gratuit unique, RG-044).
 */
final readonly class ProfessionalAccountVerified implements DomainEvent
{
    public function __construct(public string $profileId, public string $userId, public \DateTimeImmutable $at)
    {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
