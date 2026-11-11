<?php

declare(strict_types=1);

namespace App\Identity\Application\Port;

use App\Identity\Application\Legal\LegalDocumentVersion;

/** Preuves d'acceptation, en ajout seul (RG-002) : utilisateur, version, empreinte du texte, date, IP hachée. */
interface LegalAcceptanceLog
{
    public function record(string $id, string $userId, LegalDocumentVersion $document, \DateTimeImmutable $at, ?string $ipHash): void;

    public function hasAccepted(string $userId, LegalDocumentVersion $document): bool;
}
