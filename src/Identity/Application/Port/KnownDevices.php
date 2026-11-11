<?php

declare(strict_types=1);

namespace App\Identity\Application\Port;

/** Appareils déjà utilisés pour se connecter (E-05). Identifiés par l'empreinte d'un cookie aléatoire, pas par empreinte navigateur. */
interface KnownDevices
{
    /**
     * Enregistre la connexion depuis cet appareil.
     *
     * @return bool vrai si l'appareil était inconnu pour ce compte
     */
    public function touch(string $id, string $userId, string $deviceHash, string $description, ?string $ipHash, \DateTimeImmutable $now): bool;

    public function count(string $userId): int;
}
