<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\EventHandler;

use App\ArtisanProfile\Domain\Document\ArtisanDocumentRepository;
use App\Shared\Application\Bus\EventHandler;
use App\Shared\Domain\Storage\FileAvailable;
use App\Shared\Domain\Storage\FilePurpose;
use App\Shared\Domain\Storage\FileRejected;
use App\Shared\Domain\Time\Clock;

/** Résultat de l'analyse antivirus d'un justificatif : à contrôler (BO03) ou écarté (l'artisan le voit à l'étape 6). */
final readonly class TrackDocumentScan implements EventHandler
{
    public function __construct(private ArtisanDocumentRepository $documents, private Clock $clock)
    {
    }

    public function __invoke(FileAvailable|FileRejected $event): void
    {
        if (FilePurpose::ArtisanDocument->value !== $event->purpose) {
            return;
        }
        $document = $this->documents->findByFileId($event->fileId);
        if (null === $document) {
            return;
        }
        $event instanceof FileAvailable ? $document->markScanned($this->clock->now()) : $document->markRejectedByScan($this->clock->now());
    }
}
