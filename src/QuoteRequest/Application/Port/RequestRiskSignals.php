<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Port;

/** RG-063 — mesures sur les demandes déjà envoyées (rafales, textes identiques). */
interface RequestRiskSignals
{
    public function countByClientSince(string $clientId, \DateTimeImmutable $since): int;

    public function countByIpSince(string $ipHash, \DateTimeImmutable $since): int;

    /** Une demande au texte identique (casse, ponctuation et espaces ignorés) a-t-elle été envoyée depuis `$since` ? */
    public function hasSameDescriptionSince(string $description, \DateTimeImmutable $since): bool;
}
