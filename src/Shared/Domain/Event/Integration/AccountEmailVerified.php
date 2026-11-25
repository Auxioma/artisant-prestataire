<?php

declare(strict_types=1);

namespace App\Shared\Domain\Event\Integration;

use App\Shared\Domain\Event\DomainEvent;

/**
 * Événement d'intégration : adresse e-mail d'un compte validée à l'inscription (A06, RG-040). Publié par Identity,
 * consommé par les modules qui n'en dépendent pas (QuoteRequest : envoi de la demande en attente d'activation, P11).
 */
final readonly class AccountEmailVerified implements DomainEvent
{
    public function __construct(public string $userId, public \DateTimeImmutable $at)
    {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
