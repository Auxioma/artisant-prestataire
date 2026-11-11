<?php

declare(strict_types=1);

namespace App\Identity\Application\Legal;

use App\Shared\Application\Bus\Command;

/** BO > CMS > Pages légales (RG-002). `substantial` : ré-acceptation exigée à la connexion suivante. */
final readonly class PublishLegalDocument implements Command
{
    public function __construct(
        public string $documentId,
        public string $type,
        public string $title,
        public string $html,
        public bool $substantial,
        public ?string $actorId = null,
    ) {
    }
}
