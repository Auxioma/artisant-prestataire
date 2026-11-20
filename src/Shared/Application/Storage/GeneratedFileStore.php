<?php

declare(strict_types=1);

namespace App\Shared\Application\Storage;

use App\Shared\Application\Storage\Port\EnvelopeEncryption;
use App\Shared\Application\Storage\Port\ObjectStorage;
use App\Shared\Application\Storage\Port\StorageZone;
use App\Shared\Application\Storage\Port\StoredFileRepository;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Storage\FilePurpose;
use App\Shared\Domain\Storage\FileStatus;
use App\Shared\Domain\Storage\FileType;
use App\Shared\Domain\Time\Clock;

/**
 * Fichier produit par l'application elle-même (export RGPD…) : aucune analyse antivirus (contenu maîtrisé), écrit
 * directement dans la zone `documents`, chiffré si l'usage l'exige.
 */
final readonly class GeneratedFileStore
{
    public function __construct(
        private ObjectStorage $storage,
        private StoredFileRepository $files,
        private EnvelopeEncryption $encryption,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    public function store(string $path, FilePurpose $purpose, FileType $type, ?string $name = null): StoredFile
    {
        $size = filesize($path);
        $sha256 = hash_file('sha256', $path);
        $plain = fopen($path, 'r');
        if (false === $size || 0 === $size || false === $sha256 || false === $plain) {
            throw new \RuntimeException('Fichier généré illisible.');
        }
        $now = $this->clock->now();
        $file = new StoredFile($this->ids->next(), $purpose, FileStatus::Quarantined, $type, $size, $sha256, $name, $now);
        $key = null;
        try {
            if ($purpose->encryptedAtRest()) {
                $cipher = fopen('php://temp/maxmemory:2097152', 'w+');
                if (false === $cipher) {
                    throw new \RuntimeException('Flux temporaire indisponible.');
                }
                try {
                    $key = $this->encryption->encrypt($plain, $cipher);
                    rewind($cipher);
                    $this->storage->write(StorageZone::Documents, $file->id, $cipher);
                } finally {
                    fclose($cipher);
                }
            } else {
                $this->storage->write(StorageZone::Documents, $file->id, $plain);
            }
        } finally {
            fclose($plain);
        }
        $this->files->add($file);
        $this->files->markAvailable($file->id, $key, $now);

        return new StoredFile($file->id, $purpose, FileStatus::Available, $type, $size, $sha256, $name, $now, $key);
    }
}
