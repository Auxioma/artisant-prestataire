<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Maintenance;

use App\ArtisanProfile\Application\Port\MaintenanceCandidates;
use App\ArtisanProfile\Domain\Document\ArtisanDocumentRepository;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Application\Storage\FileShredder;
use App\Shared\Domain\Time\Clock;

/**
 * CDC §11 « Justificatifs artisans : durée de la relation + 1 an » : la fin de la relation est l'effacement du compte
 * ou le refus du dossier ; passé documents.retention_after_relation_years, chaque pièce est détruite (crypto-shredding,
 * journalisé par FileShredder) et marquée « détruite ». Les métadonnées (type, dates, décision) restent pour l'historique.
 */
final readonly class PurgeEndedRelationDocumentsHandler implements CommandHandler
{
    public function __construct(
        private MaintenanceCandidates $candidates,
        private ArtisanDocumentRepository $documents,
        private FileShredder $shredder,
        private SettingsProvider $settings,
        private Clock $clock,
    ) {
    }

    public function __invoke(PurgeEndedRelationDocuments $command): void
    {
        $now = $this->clock->now();
        $before = $now->modify(\sprintf('-%d years', $this->settings->int(SettingKey::DocumentsRetentionAfterRelationYears)));
        foreach ($this->candidates->documentsOfRelationsEndedBefore($before) as $id) {
            $document = $this->documents->find($id);
            if (null === $document) {
                continue;
            }
            $this->shredder->shred($document->fileId(), 'document_retention');
            $document->shredForRetention($now);
        }
    }
}
