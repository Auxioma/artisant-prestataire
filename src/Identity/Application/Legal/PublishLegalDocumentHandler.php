<?php

declare(strict_types=1);

namespace App\Identity\Application\Legal;

use App\Identity\Application\Port\LegalDocuments;
use App\Identity\Domain\Legal\Event\LegalDocumentPublished;
use App\Identity\Domain\Legal\LegalDocumentType;
use App\Identity\Domain\User\IdentityRuleViolation;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Bus\EventBus;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

final readonly class PublishLegalDocumentHandler implements CommandHandler
{
    private const int TITLE_MAX = 200;

    public function __construct(private LegalDocuments $documents, private AuditTrail $audit, private EventBus $events, private IdGenerator $ids, private Clock $clock)
    {
    }

    public function __invoke(PublishLegalDocument $command): void
    {
        $type = LegalDocumentType::from($command->type);
        $title = trim($command->title);
        if ('' === $title || mb_strlen($title) > self::TITLE_MAX || '' === trim(strip_tags($command->html))) {
            throw new IdentityRuleViolation('Titre et contenu du document obligatoires.');
        }
        $now = $this->clock->now();
        $version = $this->documents->publish($command->documentId, $type, $title, $command->html, $command->substantial, $now, $command->actorId);
        $this->events->publish(new LegalDocumentPublished($version->id, $type->value, $version->version, $version->substantial, $now));

        $this->audit->record(new AuditRecord(
            id: $this->ids->next(),
            occurredAt: $now,
            actorType: null === $command->actorId ? 'system' : 'admin',
            actorId: $command->actorId,
            action: 'legal.document_published',
            subjectType: 'legal_document',
            subjectId: $version->id,
            context: ['type' => $type->value, 'version' => $version->version, 'substantial' => $version->substantial, 'hash' => $version->contentHash],
        ));
    }
}
