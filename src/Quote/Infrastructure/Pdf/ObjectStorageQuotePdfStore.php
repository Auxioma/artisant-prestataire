<?php

declare(strict_types=1);

namespace App\Quote\Infrastructure\Pdf;

use App\Quote\Application\Port\QuotePdfStore;
use App\Shared\Application\Storage\Port\ObjectStorage;
use App\Shared\Application\Storage\Port\StorageZone;

final readonly class ObjectStorageQuotePdfStore implements QuotePdfStore
{
    public function __construct(private ObjectStorage $storage)
    {
    }

    public function put(string $key, string $bytes): void
    {
        $stream = fopen('php://temp', 'w+');
        if (false === $stream) {
            throw new \RuntimeException('Flux temporaire indisponible.');
        }
        fwrite($stream, $bytes);
        rewind($stream);
        try {
            $this->storage->write(StorageZone::Documents, $key, $stream);
        } finally {
            fclose($stream);
        }
    }

    public function get(string $key): string
    {
        $stream = $this->storage->read(StorageZone::Documents, $key);
        $bytes = stream_get_contents($stream);
        fclose($stream);

        return false === $bytes ? '' : $bytes;
    }
}
