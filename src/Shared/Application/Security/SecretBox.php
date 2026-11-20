<?php

declare(strict_types=1);

namespace App\Shared\Application\Security;

/**
 * Chiffrement authentifié de secrets courts conservés en base (secret TOTP admin…), avec la clé maîtresse versionnée
 * (ADR-0008). Le résultat porte l'identifiant de la clé : la rotation n'empêche pas la lecture des anciens secrets.
 */
interface SecretBox
{
    public function seal(#[\SensitiveParameter] string $secret): string;

    /** @throws \RuntimeException secret altéré ou clé inconnue */
    public function open(string $sealed): string;
}
