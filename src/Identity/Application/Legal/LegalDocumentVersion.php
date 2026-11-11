<?php

declare(strict_types=1);

namespace App\Identity\Application\Legal;

use App\Identity\Domain\Legal\LegalDocumentType;

/** Version publiée (figée) d'un document légal ; `contentHash` est repris dans chaque preuve d'acceptation. */
final readonly class LegalDocumentVersion
{
    public function __construct(
        public string $id,
        public LegalDocumentType $type,
        public int $version,
        public string $title,
        public string $html,
        public string $contentHash,
        public bool $substantial,
        public \DateTimeImmutable $publishedAt,
    ) {
    }
}
