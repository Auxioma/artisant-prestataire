<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Domain\Document;

interface ArtisanDocumentRepository
{
    public function add(ArtisanDocument $document): void;

    public function findByFileId(string $fileId): ?ArtisanDocument;

    /**
     * Pièces non remplacées d'un artisan, plus récentes d'abord.
     *
     * @return list<ArtisanDocument>
     */
    public function currentOf(string $artisanId): array;
}
