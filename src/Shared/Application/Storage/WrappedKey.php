<?php

declare(strict_types=1);

namespace App\Shared\Application\Storage;

/** Clé de données (DEK) chiffrée par la clé maîtresse `kekId` (chiffrement enveloppe, ADR-0008). */
final readonly class WrappedKey
{
    public function __construct(public string $kekId, public string $wrappedDek)
    {
    }
}
