<?php

declare(strict_types=1);

namespace App\Identity\Domain\User\Event;

use App\Shared\Domain\Event\DomainEvent;

/** Inscription tentée avec l'e-mail d'un compte existant : le titulaire en est informé (anti-énumération, A02). */
final readonly class RegistrationAttemptedOnExistingAccount implements DomainEvent
{
    public function __construct(public string $userId, public \DateTimeImmutable $at)
    {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
