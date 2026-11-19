<?php

declare(strict_types=1);

namespace App\Administration\Application\Logs;

/** Une ligne du journal d'audit telle qu'affichée (l'IP est masquée à la lecture, jamais affichée en clair). */
final readonly class AuditLogEntry
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public string $id,
        public \DateTimeImmutable $occurredAt,
        public string $actorType,
        public ?string $actorName,
        public string $action,
        public ?string $subjectType,
        public ?string $subjectId,
        public ?string $maskedIp,
        public bool $impersonated,
        public array $context,
    ) {
    }

    public function module(): string
    {
        return explode('.', $this->action, 2)[0];
    }
}
