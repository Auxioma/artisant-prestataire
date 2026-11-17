<?php

declare(strict_types=1);

namespace App\Identity\Application\Legal;

use App\Identity\Application\Port\LegalAcceptanceLog;
use App\Identity\Domain\User\UserId;
use App\Identity\Domain\User\UserRepository;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

final readonly class AcceptLegalUpdatesHandler implements CommandHandler
{
    public function __construct(
        private UserRepository $users,
        private LegalReacceptanceHandler $pending,
        private LegalAcceptanceLog $acceptances,
        private AuditTrail $audit,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    public function __invoke(AcceptLegalUpdates $command): void
    {
        $user = $this->users->find(new UserId($command->userId));
        if (null === $user) {
            return;
        }
        $now = $this->clock->now();
        foreach ($this->pending->pending($user) as $document) {
            $this->acceptances->record($this->ids->next(), $command->userId, $document, $now, $command->ipHash);
            $this->audit->record(new AuditRecord(
                id: $this->ids->next(),
                occurredAt: $now,
                actorType: 'user',
                actorId: $command->userId,
                action: 'legal.document_accepted',
                subjectType: 'legal_document',
                subjectId: $document->id,
                context: ['type' => $document->type->value, 'version' => $document->version],
            ));
        }
    }
}
