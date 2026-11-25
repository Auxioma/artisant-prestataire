<?php

declare(strict_types=1);

namespace App\Shared\Application\Moderation;

/** Instantané d'un contenu signalé : titre, extrait, titulaire (l'auteur ne peut pas signaler son propre contenu). */
final readonly class ReportedContent
{
    public function __construct(public string $title, public string $excerpt, public ?string $ownerUserId)
    {
    }
}
