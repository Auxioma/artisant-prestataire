<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Domain\Profile\Event;

use App\Shared\Domain\Event\DomainEvent;

/** Dossier validé (RG-043) : profil public, envoi de devis ; crédit gratuit unique (RG-044, T-211), E-21 (T-210). */
final readonly class ArtisanVerified implements DomainEvent
{
    public function __construct(public string $profileId, public string $userId, public string $adminId, public \DateTimeImmutable $at)
    {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
