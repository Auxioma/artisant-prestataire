<?php

declare(strict_types=1);

namespace App\Identity\Application\Port;

/** Appareils déjà utilisés pour se connecter (E-05). Identifiés par l'empreinte d'un cookie aléatoire, pas par empreinte navigateur. */
interface KnownDevices
{
    /** Cookie aléatoire identifiant l'appareil (posé à la connexion). */
    public const string COOKIE = 'tm_device';

    /**
     * Enregistre la connexion depuis cet appareil.
     *
     * @return bool vrai si l'appareil était inconnu pour ce compte
     */
    public function touch(string $id, string $userId, string $deviceHash, string $description, ?string $ipHash, \DateTimeImmutable $now): bool;

    public function count(string $userId): int;

    /**
     * C07.2 « Appareils connectés » : appareils non révoqués, du plus récent au plus ancien.
     *
     * @return list<array{id: string, hash: string, description: string, firstSeen: \DateTimeImmutable, lastSeen: \DateTimeImmutable}>
     */
    public function active(string $userId): array;

    /** Révoque un appareil (ou tous sauf celui d'empreinte `$keepHash` si `$deviceId` est null) ; renvoie le nombre révoqué. */
    public function revoke(string $userId, ?string $deviceId, ?string $keepHash, \DateTimeImmutable $at): int;

    public function isRevoked(string $userId, string $deviceHash): bool;

    /** RG-090 : effacement des appareils d'un compte supprimé. */
    public function forget(string $userId): void;

    /** CDC §11 « logs de connexion 1 an » : appareils non revus depuis `$before`. */
    public function forgetIdleBefore(\DateTimeImmutable $before): int;
}
