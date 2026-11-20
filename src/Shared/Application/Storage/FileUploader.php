<?php

declare(strict_types=1);

namespace App\Shared\Application\Storage;

use App\Shared\Application\Bus\EventBus;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Application\Storage\Port\FileSanitizer;
use App\Shared\Application\Storage\Port\ObjectStorage;
use App\Shared\Application\Storage\Port\StorageZone;
use App\Shared\Application\Storage\Port\StoredFileRepository;
use App\Shared\Application\Transaction\TransactionManager;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Storage\FilePurpose;
use App\Shared\Domain\Storage\FileQuarantined;
use App\Shared\Domain\Storage\FileStatus;
use App\Shared\Domain\Time\Clock;

/**
 * Entrée unique du pipeline d'upload (ADR-0008, RG-061) : taille → type réel et assainissement → quarantaine →
 * analyse antivirus asynchrone. Appelé par les cas d'usage des modules propriétaires (justificatif, photo…), qui
 * conservent l'identifiant renvoyé et attendent `FileAvailable` avant toute exposition.
 */
final readonly class FileUploader
{
    private const int ORIGINAL_NAME_MAX = 120;
    private const int BYTES_PER_MB = 1_048_576;

    public function __construct(
        private FileSanitizer $sanitizer,
        private ObjectStorage $storage,
        private StoredFileRepository $files,
        private EventBus $events,
        private TransactionManager $transactions,
        private SettingsProvider $settings,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    /**
     * @param string $path fichier temporaire reçu (jamais servi, jamais déplacé tel quel)
     *
     * @throws RejectedUpload
     */
    public function upload(string $path, ?string $clientName, FilePurpose $purpose): StoredFile
    {
        $size = is_file($path) ? filesize($path) : false;
        if (false === $size || 0 === $size) {
            throw new RejectedUpload(RejectedUpload::EMPTY);
        }
        if ($size > $this->settings->int(SettingKey::RequestsMaxFileMb) * self::BYTES_PER_MB) {
            throw new RejectedUpload(RejectedUpload::TOO_LARGE);
        }

        $sanitized = $this->sanitizer->sanitize($path, $purpose->allowedTypes());
        try {
            $finalSize = filesize($sanitized->path);
            $sha256 = hash_file('sha256', $sanitized->path);
            if (false === $finalSize || false === $sha256) {
                throw new RejectedUpload(RejectedUpload::UNREADABLE);
            }
            $file = new StoredFile($this->ids->next(), $purpose, FileStatus::Quarantined, $sanitized->type, $finalSize, $sha256, self::cleanName($clientName), $this->clock->now());

            $stream = fopen($sanitized->path, 'r');
            if (false === $stream) {
                throw new RejectedUpload(RejectedUpload::UNREADABLE);
            }
            try {
                $this->storage->write(StorageZone::Quarantine, $file->id, $stream);
            } finally {
                fclose($stream);
            }
        } finally {
            if ($sanitized->path !== $path && is_file($sanitized->path)) {
                unlink($sanitized->path);
            }
        }

        try {
            $this->transactions->run(function () use ($file): void {
                $this->files->add($file);
                $this->events->publish(new FileQuarantined($file->id, $file->purpose->value, $file->createdAt));
            });
        } catch (\Throwable $e) {
            $this->storage->delete(StorageZone::Quarantine, $file->id); // pas d'objet orphelin
            throw $e;
        }

        return $file;
    }

    /** Nom d'origine à titre informatif : sans chemin, sans caractères de contrôle, longueur bornée. */
    private static function cleanName(?string $name): ?string
    {
        if (null === $name) {
            return null;
        }
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\p{C}<>"]+/u', '', $name);
        $name = trim(mb_substr($name, 0, self::ORIGINAL_NAME_MAX));

        return '' === $name ? null : $name;
    }
}
