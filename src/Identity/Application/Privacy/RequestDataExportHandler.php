<?php

declare(strict_types=1);

namespace App\Identity\Application\Privacy;

use App\Identity\Domain\Privacy\DataExportRequested;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Bus\EventBus;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

/** Une demande à la fois, et au plus une par 24 h (l'archive précédente reste téléchargeable). */
final readonly class RequestDataExportHandler implements CommandHandler
{
    public const int COOLDOWN_HOURS = 24;

    public function __construct(private DataExports $exports, private EventBus $events, private AuditTrail $audit, private IdGenerator $ids, private Clock $clock)
    {
    }

    public function __invoke(RequestDataExport $command): void
    {
        $now = $this->clock->now();
        $latest = $this->exports->latest($command->userId);
        if (null !== $latest && 'requested' === $latest->status) {
            throw new DataExportRejected('Votre archive est en cours de préparation : vous recevrez un e-mail dès qu’elle sera prête.');
        }
        if (null !== $latest && 'failed' !== $latest->status && $latest->requestedAt > $now->modify(\sprintf('-%d hours', self::COOLDOWN_HOURS))) {
            throw new DataExportRejected('Une archive a déjà été demandée il y a moins de 24 heures.');
        }
        $this->exports->create($command->exportId, $command->userId, $now);
        $this->events->publish(new DataExportRequested($command->exportId, $command->userId, $now));
        $this->audit->record(new AuditRecord(
            id: $this->ids->next(),
            occurredAt: $now,
            actorType: 'user',
            actorId: $command->userId,
            action: 'gdpr.export_requested',
            subjectType: 'user',
            subjectId: $command->userId,
            ip: $command->ip,
        ));
    }
}
