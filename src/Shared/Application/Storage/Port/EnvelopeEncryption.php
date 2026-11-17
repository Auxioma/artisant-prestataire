<?php

declare(strict_types=1);

namespace App\Shared\Application\Storage\Port;

use App\Shared\Application\Storage\WrappedKey;

/** Chiffrement enveloppe : une clé de données par fichier, elle-même chiffrée par une clé maîtresse versionnée. */
interface EnvelopeEncryption
{
    /**
     * @param resource $plain
     * @param resource $cipher
     */
    public function encrypt($plain, $cipher): WrappedKey;

    /**
     * @param resource $cipher
     * @param resource $plain
     *
     * @throws \RuntimeException flux altéré ou clé maîtresse inconnue
     */
    public function decrypt($cipher, $plain, WrappedKey $key): void;
}
