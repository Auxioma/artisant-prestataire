<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Signup;

use App\ArtisanProfile\Domain\Document\ArtisanDocument;
use App\ArtisanProfile\Domain\Document\ArtisanDocumentRepository;
use App\ArtisanProfile\Domain\Profile\ArtisanProfile;
use App\ArtisanProfile\Domain\Profile\ArtisanProfileRepository;
use App\ArtisanProfile\Domain\Profile\ArtisanRuleViolation;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Storage\FileShredder;
use App\Shared\Application\Storage\FileUploader;
use App\Shared\Application\Storage\RejectedUpload;
use App\Shared\Domain\Storage\FilePurpose;
use App\Shared\Domain\Time\Clock;

/**
 * Pipeline ADR-0008 (taille, type réel, assainissement, quarantaine, antivirus asynchrone, chiffrement). Une nouvelle
 * pièce remplace la précédente du même emplacement, dont le fichier est détruit (crypto-shredding).
 */
final readonly class UploadDocumentHandler implements CommandHandler
{
    public function __construct(
        private ArtisanProfileRepository $profiles,
        private ArtisanDocumentRepository $documents,
        private SignupRequirements $requirements,
        private FileUploader $uploader,
        private FileShredder $shredder,
        private Clock $clock,
    ) {
    }

    public function __invoke(UploadDocument $command): void
    {
        $profile = $this->profiles->findByUserId($command->userId) ?? throw new ArtisanRuleViolation('Déclarez d’abord votre entreprise.');
        if ($profile->completedStep() < ArtisanProfile::STEP_INSURANCE) {
            throw new ArtisanRuleViolation('Déclarez d’abord vos assurances.');
        }
        $current = $this->documents->currentOf($profile->id()->value);
        $slot = null;
        foreach ($this->requirements->checklist($profile, $current) as $candidate) {
            if ($candidate->slotKey() === $command->slot) {
                $slot = $candidate;
            }
        }
        if (null === $slot) {
            throw new SignupRejected('slot', SignupRejected::DOCUMENT_UNEXPECTED, 'Choisissez le type de justificatif.');
        }

        try {
            $file = $this->uploader->upload($command->path, $command->clientName, FilePurpose::ArtisanDocument);
        } catch (RejectedUpload $e) {
            throw new SignupRejected('file', SignupRejected::FILE_REJECTED, $e->reason);
        }

        $now = $this->clock->now();
        foreach ($current as $previous) {
            if (DocumentSlot::key($previous->type(), $previous->subject()) === $command->slot) {
                $previous->supersede($now);
                $this->shredder->shred($previous->fileId(), 'artisan_document_replaced'); // acteur « système » : remplacement par le titulaire
            }
        }
        $this->documents->add(ArtisanDocument::upload($command->documentId, $profile->id()->value, $slot->type, $slot->subject, $file->id, $file->originalName, $slot->expiresOn, $now));
    }
}
