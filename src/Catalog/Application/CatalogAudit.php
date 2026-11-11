<?php

declare(strict_types=1);

namespace App\Catalog\Application;

use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

/** Journalise les modifications du référentiel (BO15 : historique qui / quoi / quand). */
final readonly class CatalogAudit
{
    public function __construct(private AuditTrail $trail, private IdGenerator $ids, private Clock $clock)
    {
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public function record(?string $actorId, string $action, string $subjectType, string $subjectId, array $context = []): void
    {
        $this->trail->record(new AuditRecord(
            id: $this->ids->next(),
            occurredAt: $this->clock->now(),
            actorType: null === $actorId ? 'system' : 'admin',
            actorId: $actorId,
            action: 'catalog.'.$action,
            subjectType: $subjectType,
            subjectId: $subjectId,
            context: $context,
        ));
    }
}
