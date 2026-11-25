<?php

declare(strict_types=1);

namespace App\Shared\Application\Storage;

use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Storage\Port\ObjectStorage;
use App\Shared\Application\Storage\Port\StorageZone;
use App\Shared\Application\Storage\Port\StoredFileRepository;
use App\Shared\Application\Transaction\TransactionManager;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Storage\FileStatus;
use App\Shared\Domain\Time\Clock;

/**
 * Destruction définitive (Q16 : pièce d'identité après décision ; durées de conservation RGPD). Pour un fichier
 * chiffré, l'effacement de la clé enveloppée rend toute copie résiduelle (sauvegardes S3) illisible.
 */
final readonly class FileShredder
{
    public function __construct(
        private StoredFileRepository $files,
        private ObjectStorage $storage,
        private TransactionManager $transactions,
        private AuditTrail $audit,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    public function shred(string $id, string $reason, ?string $actorId = null): void
    {
        $file = $this->files->find($id);
        if (null === $file || FileStatus::Shredded === $file->status) {
            return;
        }
        $now = $this->clock->now();
        $this->transactions->run(function () use ($file, $reason, $actorId, $now): void {
            $this->files->markShredded($file->id, $now);
            $this->audit->record(new AuditRecord(
                id: $this->ids->next(),
                occurredAt: $now,
                actorType: null === $actorId ? 'system' : 'admin',
                actorId: $actorId,
                action: 'file.shredded',
                subjectType: 'stored_file',
                subjectId: $file->id,
                context: ['purpose' => $file->purpose->value, 'reason' => $reason, 'encrypted' => $file->encrypted()],
            ));
        });
        $this->storage->delete(StorageZone::Documents, $file->id);
        $this->storage->delete(StorageZone::Quarantine, $file->id);
    }
}
