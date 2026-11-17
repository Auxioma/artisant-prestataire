<?php

declare(strict_types=1);

namespace App\Identity\Application\Port;

use App\Identity\Domain\User\UserKind;

/** Comptes actifs à informer d'une mise à jour des CGU / CGV (E-95), lus par lots ordonnés. */
interface LegalUpdateRecipients
{
    /**
     * @return list<array{id: string, email: string, first_name: string}>
     */
    public function batch(?UserKind $kind, ?string $afterId, int $limit): array;
}
