<?php

declare(strict_types=1);

namespace App\Content\Application\Port;

/** Inscrits à la newsletter : une ligne par adresse (insensible à la casse), réactivée si l'adresse se réinscrit. */
interface NewsletterSubscribers
{
    /** Inscrit l'adresse, ou la réactive si elle s'était désinscrite ; sans effet si elle est déjà inscrite. */
    public function subscribe(string $id, string $email, string $token, string $source, ?string $ipHash, \DateTimeImmutable $at): void;

    /** @return bool false si le lien est inconnu */
    public function unsubscribe(string $token, \DateTimeImmutable $at): bool;
}
