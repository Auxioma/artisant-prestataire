<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Signup;

use App\Shared\Application\Bus\Command;

/** A03.6 — dépôt d'un justificatif pour une pièce attendue (`slot` : clé de DocumentSlot). */
final readonly class UploadDocument implements Command
{
    public function __construct(
        public string $documentId,
        public string $userId,
        public string $slot,
        public string $path,
        public ?string $clientName,
    ) {
    }
}
