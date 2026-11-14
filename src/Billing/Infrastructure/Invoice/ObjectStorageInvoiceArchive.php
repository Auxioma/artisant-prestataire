<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Invoice;

use App\Billing\Application\Invoice\InvoiceArchive;
use App\Shared\Application\Storage\Port\ObjectStorage;
use App\Shared\Application\Storage\Port\StorageZone;

/** Zone « documents » du stockage objet (privée, chiffrée au repos, ADR-0008). */
final readonly class ObjectStorageInvoiceArchive implements InvoiceArchive
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
