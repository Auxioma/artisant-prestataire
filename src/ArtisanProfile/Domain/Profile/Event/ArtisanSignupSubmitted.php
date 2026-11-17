<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Domain\Profile\Event;

use App\Shared\Domain\Event\DomainEvent;

/** Dossier d'inscription envoyé (A03.7) : statut « en attente de vérification » (RG-043) — E-20, file BO03 (T-210). */
final readonly class ArtisanSignupSubmitted implements DomainEvent
{
    public function __construct(public string $profileId, public string $userId, public \DateTimeImmutable $at)
    {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
