<?php

declare(strict_types=1);

namespace App\Shared\Application\Storage\Port;

use App\Shared\Application\Storage\StoredFile;
use App\Shared\Application\Storage\WrappedKey;

interface StoredFileRepository
{
    public function add(StoredFile $file): void;

    public function find(string $id): ?StoredFile;

    public function markAvailable(string $id, ?WrappedKey $key, \DateTimeImmutable $at): void;

    public function markRejected(string $id, string $reason, \DateTimeImmutable $at): void;

    /** Efface la clé enveloppée (crypto-shredding) en plus du statut. */
    public function markShredded(string $id, \DateTimeImmutable $at): void;
}
