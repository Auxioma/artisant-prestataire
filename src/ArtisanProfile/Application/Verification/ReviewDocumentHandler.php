<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Verification;

use App\ArtisanProfile\Domain\Document\ArtisanDocumentRepository;
use App\ArtisanProfile\Domain\Profile\ArtisanRuleViolation;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Bus\EventBus;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

final readonly class ReviewDocumentHandler implements CommandHandler
{
    public function __construct(
        private ArtisanDocumentRepository $documents,
        private EventBus $events,
        private AuditTrail $audit,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    public function __invoke(ReviewDocument $command): void
    {
        $document = $this->documents->find($command->documentId);
        if (null === $document || $document->artisanId() !== $command->profileId) {
            throw new ArtisanRuleViolation('Justificatif introuvable.');
        }
        $now = $this->clock->now();
        match ($command->decision) {
            'approve' => $document->approve($command->adminId, $now),
            'reject' => $document->reject($command->adminId, (string) $command->reason, $now),
            default => throw new ArtisanRuleViolation('Décision inconnue.'),
        };
        $this->events->publish(...$document->releaseEvents());
        $this->audit->record(new AuditRecord(
            id: $this->ids->next(),
            occurredAt: $now,
            actorType: 'admin',
            actorId: $command->adminId,
            action: 'artisan.document_'.('approve' === $command->decision ? 'approved' : 'rejected'),
            subjectType: 'artisan_document',
            subjectId: $document->id(),
            context: ['type' => $document->type(), 'profile' => $command->profileId],
        ));
    }
}
