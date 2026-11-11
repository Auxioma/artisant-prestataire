<?php

declare(strict_types=1);

namespace App\Administration\Domain\Audit;

use App\Shared\Domain\Exception\InvalidValue;

/**
 * Entrée du journal d'audit append-only (BO15, cadrage §5.8) : qui, quoi, quand, sur quoi, depuis où.
 * Chaque entrée est scellée par un hash chaîné sur la précédente : toute altération ou suppression casse la chaîne.
 */
final readonly class AuditRecord
{
    /**
     * @param array<string, scalar|null> $context données non personnelles uniquement (identifiants, motifs, avant/après)
     */
    public function __construct(
        public string $id,
        public \DateTimeImmutable $occurredAt,
        public string $actorType,
        public ?string $actorId,
        public string $action,
        public ?string $subjectType = null,
        public ?string $subjectId = null,
        public ?string $ip = null,
        public ?string $impersonatorId = null,
        public array $context = [],
    ) {
        if (1 !== preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/', $action)) {
            throw InvalidValue::for('Action d\'audit', 'format module.action attendu');
        }
        if (!\in_array($actorType, ['user', 'admin', 'system'], true)) {
            throw InvalidValue::for('Acteur d\'audit', 'user, admin ou system attendu');
        }
    }

    /** Représentation canonique déterministe (clés triées, UTC, microsecondes). */
    public function canonical(): string
    {
        $context = $this->context;
        ksort($context);

        return json_encode([
            'id' => $this->id,
            'occurred_at' => $this->occurredAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP'),
            'actor_type' => $this->actorType,
            'actor_id' => $this->actorId,
            'impersonator_id' => $this->impersonatorId,
            'action' => $this->action,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'ip' => $this->ip,
            'context' => $context,
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    public function seal(?string $previousHash): string
    {
        return hash('sha256', ($previousHash ?? '').'|'.$this->canonical());
    }
}
