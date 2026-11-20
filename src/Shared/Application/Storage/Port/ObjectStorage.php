<?php

declare(strict_types=1);

namespace App\Shared\Application\Storage\Port;

/** Stockage objet privé (S3) ; les flux sont des ressources PHP lisibles. */
interface ObjectStorage
{
    /** @param resource $stream */
    public function write(StorageZone $zone, string $key, $stream): void;

    /** @return resource */
    public function read(StorageZone $zone, string $key);

    public function delete(StorageZone $zone, string $key): void;

    /** URL pré-signée de lecture, à générer uniquement après autorisation (Voter). */
    public function temporaryUrl(string $key, \DateTimeImmutable $expiresAt, string $downloadName, string $contentType): string;
}
