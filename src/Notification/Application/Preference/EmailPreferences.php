<?php

declare(strict_types=1);

namespace App\Notification\Application\Preference;

use App\Shared\Application\Bus\Query;

/** Réponse : array<string, bool> (valeur de NotificationTopic → reçu par e-mail), tous les sujets présents. */
final readonly class EmailPreferences implements Query
{
    public function __construct(public string $userId)
    {
    }
}
