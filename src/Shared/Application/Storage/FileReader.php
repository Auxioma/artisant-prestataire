<?php

declare(strict_types=1);

namespace App\Shared\Application\Storage;

use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Application\Storage\Port\EnvelopeEncryption;
use App\Shared\Application\Storage\Port\ObjectStorage;
use App\Shared\Application\Storage\Port\StorageZone;
use App\Shared\Application\Storage\Port\StoredFileRepository;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Storage\FileStatus;
use App\Shared\Domain\Time\Clock;

/**
 * Lecture d'un fichier disponible. L'autorisation (Voter du module propriétaire) est vérifiée AVANT tout appel.
 * Chiffré : déchiffré en flux par un contrôleur, chaque consultation journalisée. Sinon : URL signée courte.
 */
final readonly class FileReader
{
    public function __construct(
        private StoredFileRepository $files,
        private ObjectStorage $storage,
        private EnvelopeEncryption $encryption,
        private SettingsProvider $settings,
        private AuditTrail $audit,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    /** @throws StoredFileNotFound */
    public function available(string $id): StoredFile
    {
        $file = $this->files->find($id);
        if (null === $file || FileStatus::Available !== $file->status) {
            throw StoredFileNotFound::withId($id);
        }

        return $file;
    }

    /**
     * @throws StoredFileNotFound fichier absent, non disponible ou chiffré (à lire via openForActor)
     */
    public function signedUrl(string $id): string
    {
        $file = $this->available($id);
        if ($file->encrypted()) {
            throw StoredFileNotFound::withId($id);
        }
        $ttl = $this->settings->int(SettingKey::DocumentsSignedUrlTtlSec);

        return $this->storage->temporaryUrl($file->id, $this->clock->now()->modify(\sprintf('+%d seconds', $ttl)), $file->downloadName(), $file->type->value);
    }

    /**
     * Contenu en clair (déchiffré si nécessaire) ; la consultation est journalisée (qui, quoi, quand, IP).
     *
     * @return resource
     *
     * @throws StoredFileNotFound
     */
    public function openForActor(string $id, string $actorType, string $actorId, ?string $ip)
    {
        $file = $this->available($id);
        $this->audit->record(new AuditRecord(
            id: $this->ids->next(),
            occurredAt: $this->clock->now(),
            actorType: $actorType,
            actorId: $actorId,
            action: 'file.read',
            subjectType: 'stored_file',
            subjectId: $file->id,
            ip: $ip,
            context: ['purpose' => $file->purpose->value],
        ));

        $source = $this->storage->read(StorageZone::Documents, $file->id);
        if (null === $file->key) {
            return $source;
        }
        $plain = fopen('php://temp/maxmemory:2097152', 'w+');
        if (false === $plain) {
            throw new \RuntimeException('Flux temporaire indisponible.');
        }
        try {
            $this->encryption->decrypt($source, $plain, $file->key);
        } finally {
            fclose($source);
        }
        rewind($plain);

        return $plain;
    }
}
