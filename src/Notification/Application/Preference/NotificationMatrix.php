<?php

declare(strict_types=1);

namespace App\Notification\Application\Preference;

use App\Shared\Application\Bus\Query;

/** Réponse : array<string, array<string, bool>> (sujet → canal → envoyé), tous sujets et canaux présents. */
final readonly class NotificationMatrix implements Query
{
    public function __construct(public string $userId)
    {
    }
}
