<?php

declare(strict_types=1);

namespace App\Shared\Application\Storage;

use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\EventBus;
use App\Shared\Application\Bus\EventHandler;
use App\Shared\Application\Storage\Port\AntivirusScanner;
use App\Shared\Application\Storage\Port\EnvelopeEncryption;
use App\Shared\Application\Storage\Port\ObjectStorage;
use App\Shared\Application\Storage\Port\StorageZone;
use App\Shared\Application\Storage\Port\StoredFileRepository;
use App\Shared\Application\Transaction\TransactionManager;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Storage\FileAvailable;
use App\Shared\Domain\Storage\FileQuarantined;
use App\Shared\Domain\Storage\FileRejected;
use App\Shared\Domain\Storage\FileStatus;
use App\Shared\Domain\Time\Clock;

/**
 * Analyse ClamAV asynchrone (cadrage §5.5). Sain : chiffré si l'usage l'exige, déplacé vers `documents`.
 * Infecté : supprimé, événement de sécurité journalisé. Démon indisponible : exception → nouvelle tentative Messenger.
 * Idempotent : un fichier qui n'est plus en quarantaine est ignoré.
 */
final readonly class ScanQuarantinedFile implements EventHandler
{
    public function __construct(
        private StoredFileRepository $files,
        private ObjectStorage $storage,
        private AntivirusScanner $scanner,
        private EnvelopeEncryption $encryption,
        private EventBus $events,
        private TransactionManager $transactions,
        private AuditTrail $audit,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    public function __invoke(FileQuarantined $event): void
    {
        $file = $this->files->find($event->fileId);
        if (null === $file || FileStatus::Quarantined !== $file->status) {
            return;
        }

        $copy = $this->localCopy($file->id);
        try {
            $signature = $this->scanner->scan($copy);
            if (null !== $signature) {
                $this->reject($file, $signature);

                return;
            }
            $key = null;
            rewind($copy);
            if ($file->purpose->encryptedAtRest()) {
                $cipher = self::temp();
                try {
                    $key = $this->encryption->encrypt($copy, $cipher);
                    rewind($cipher);
                    $this->storage->write(StorageZone::Documents, $file->id, $cipher);
                } finally {
                    fclose($cipher);
                }
            } else {
                $this->storage->write(StorageZone::Documents, $file->id, $copy);
            }
        } finally {
            fclose($copy);
        }

        $now = $this->clock->now();
        $this->transactions->run(function () use ($file, $key, $now): void {
            $this->files->markAvailable($file->id, $key, $now);
            $this->events->publish(new FileAvailable($file->id, $file->purpose->value, $now));
        });
        $this->storage->delete(StorageZone::Quarantine, $file->id);
    }

    private function reject(StoredFile $file, string $signature): void
    {
        $now = $this->clock->now();
        $this->transactions->run(function () use ($file, $signature, $now): void {
            $this->files->markRejected($file->id, 'malware', $now);
            $this->audit->record(new AuditRecord(
                id: $this->ids->next(),
                occurredAt: $now,
                actorType: 'system',
                actorId: null,
                action: 'security.malware_detected',
                subjectType: 'stored_file',
                subjectId: $file->id,
                context: ['purpose' => $file->purpose->value, 'signature' => mb_substr($signature, 0, 200), 'sha256' => $file->sha256],
            ));
            $this->events->publish(new FileRejected($file->id, $file->purpose->value, 'malware', $now));
        });
        $this->storage->delete(StorageZone::Quarantine, $file->id);
    }

    /**
     * Copie locale : un seul téléchargement pour l'analyse puis le transfert, et aucune lecture ultérieure d'un objet
     * qui aurait pu être remplacé entre-temps.
     *
     * @return resource
     */
    private function localCopy(string $id)
    {
        $source = $this->storage->read(StorageZone::Quarantine, $id);
        $copy = self::temp();
        try {
            stream_copy_to_stream($source, $copy);
        } finally {
            fclose($source);
        }
        rewind($copy);

        return $copy;
    }

    /** @return resource */
    private static function temp()
    {
        $stream = fopen('php://temp/maxmemory:2097152', 'w+');
        if (false === $stream) {
            throw new \RuntimeException('Flux temporaire indisponible.');
        }

        return $stream;
    }
}
