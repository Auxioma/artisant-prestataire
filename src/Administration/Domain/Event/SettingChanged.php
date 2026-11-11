<?php

declare(strict_types=1);

namespace App\Administration\Domain\Event;

use App\Shared\Domain\Event\DomainEvent;

final readonly class SettingChanged implements DomainEvent
{
    public function __construct(
        public string $key,
        public ?string $adminId,
        public \DateTimeImmutable $changedAt,
    ) {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->changedAt;
    }
}
