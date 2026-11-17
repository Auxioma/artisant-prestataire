<?php

declare(strict_types=1);

namespace App\Identity\Domain\User\Event;

use App\Shared\Domain\Event\DomainEvent;

/** Mot de passe changé : sessions et jetons « se souvenir de moi » à invalider (A05). */
final readonly class UserPasswordChanged implements DomainEvent
{
    public function __construct(public string $userId, public \DateTimeImmutable $at)
    {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
