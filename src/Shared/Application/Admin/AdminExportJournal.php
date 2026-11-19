<?php

declare(strict_types=1);

namespace App\Shared\Application\Admin;

use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Security\CurrentAdministrator;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

/**
 * BO15.2 — un export contenant des données personnelles est journalisé : qui, quand, depuis quelle adresse, quelle
 * liste, avec quels filtres et combien de lignes (action `admin.export`, visible dans l'onglet Exports).
 */
final readonly class AdminExportJournal
{
    public const string ACTION = 'admin.export';

    public function __construct(private AuditTrail $audit, private IdGenerator $ids, private Clock $clock, private CurrentAdministrator $admin)
    {
    }

    /** @param array<string, string|int> $filters */
    public function record(string $list, array $filters, int $rows, ?string $ip): void
    {
        $this->audit->record(new AuditRecord(
            id: $this->ids->next(),
            occurredAt: $this->clock->now(),
            actorType: 'admin',
            actorId: $this->admin->id(),
            action: self::ACTION,
            subjectType: 'export',
            subjectId: $list,
            ip: $ip,
            context: ['filters' => http_build_query($filters), 'rows' => $rows],
        ));
    }
}
