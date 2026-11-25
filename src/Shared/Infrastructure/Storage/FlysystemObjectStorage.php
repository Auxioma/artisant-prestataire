<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Storage;

use App\Shared\Application\Storage\Port\ObjectStorage;
use App\Shared\Application\Storage\Port\StorageZone;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToDeleteFile;
use Symfony\Component\DependencyInjection\Attribute\Target;

/** Deux espaces Flysystem privés (buckets S3 distincts en production, mémoire en test). */
final readonly class FlysystemObjectStorage implements ObjectStorage
{
    public function __construct(
        #[Target('quarantine.storage')]
        private FilesystemOperator $quarantine,
        #[Target('documents.storage')]
        private FilesystemOperator $documents,
    ) {
    }

    public function write(StorageZone $zone, string $key, $stream): void
    {
        $this->zone($zone)->writeStream(self::path($key), $stream, ['visibility' => 'private']);
    }

    public function read(StorageZone $zone, string $key)
    {
        return $this->zone($zone)->readStream(self::path($key));
    }

    public function delete(StorageZone $zone, string $key): void
    {
        try {
            $this->zone($zone)->delete(self::path($key));
        } catch (UnableToDeleteFile) {
            // Absent : rien à faire (suppression idempotente).
        }
    }

    /** @throws FilesystemException */
    public function temporaryUrl(string $key, \DateTimeImmutable $expiresAt, string $downloadName, string $contentType): string
    {
        return $this->documents->temporaryUrl(self::path($key), $expiresAt, ['get_object_options' => [
            'ResponseContentDisposition' => 'attachment; filename="'.$downloadName.'"',
            'ResponseContentType' => $contentType,
        ]]);
    }

    private function zone(StorageZone $zone): FilesystemOperator
    {
        return StorageZone::Quarantine === $zone ? $this->quarantine : $this->documents;
    }

    /** Clé = UUID uniquement (aucun nom fourni par l'utilisateur) ; deux niveaux de préfixe pour répartir les objets. */
    private static function path(string $key): string
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $key)) {
            throw new \InvalidArgumentException('Clé de stockage invalide.');
        }

        return substr($key, 0, 2).'/'.$key;
    }
}
