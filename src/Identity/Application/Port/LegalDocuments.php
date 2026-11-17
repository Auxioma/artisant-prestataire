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

    /**
     * Versions déjà publiées d'un document, la plus récente d'abord (« Versions précédentes », P17.1).
     *
     * @return list<LegalDocumentVersion>
     */
    public function versions(LegalDocumentType $type, \DateTimeImmutable $now): array;

    /** Dernière version publiée marquée « substantielle » (RG-002), null si aucune. */
    public function latestSubstantial(LegalDocumentType $type, \DateTimeImmutable $now): ?LegalDocumentVersion;

    /** Publie une nouvelle version (numéro suivant) ; le HTML est assaini avant stockage. */
    public function publish(string $id, LegalDocumentType $type, string $title, string $html, bool $substantial, \DateTimeImmutable $publishedAt, ?string $publishedBy): LegalDocumentVersion;
}
