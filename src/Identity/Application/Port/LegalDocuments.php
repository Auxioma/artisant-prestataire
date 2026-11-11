<?php

declare(strict_types=1);

namespace App\Identity\Application\Port;

use App\Identity\Application\Legal\LegalDocumentVersion;
use App\Identity\Domain\Legal\LegalDocumentType;

interface LegalDocuments
{
    /** Version en vigueur (la plus récente déjà publiée), null si aucune. */
    public function current(LegalDocumentType $type, \DateTimeImmutable $now): ?LegalDocumentVersion;

    public function get(string $id): ?LegalDocumentVersion;

    /** Publie une nouvelle version (numéro suivant) ; le HTML est assaini avant stockage. */
    public function publish(string $id, LegalDocumentType $type, string $title, string $html, bool $substantial, \DateTimeImmutable $publishedAt, ?string $publishedBy): LegalDocumentVersion;
}
