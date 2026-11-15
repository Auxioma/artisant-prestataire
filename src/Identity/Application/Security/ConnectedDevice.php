<?php

declare(strict_types=1);

namespace App\Identity\Application\Security;

final readonly class ConnectedDevice
{
    public function __construct(
        public string $id,
        public string $description,
        public \DateTimeImmutable $firstSeen,
        public \DateTimeImmutable $lastSeen,
        public bool $current,
    ) {
    }
}
