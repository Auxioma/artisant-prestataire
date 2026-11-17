<?php

declare(strict_types=1);

namespace App\Notification\Application\Preference;

use App\Shared\Application\Bus\Command;

/** C07 « Mes notifications » : sujets reçus par e-mail (les autres sujets restent visibles dans le centre C08). */
final readonly class ChangeEmailPreferences implements Command
{
    /** @param array<string, bool> $topics valeur de NotificationTopic → reçu par e-mail */
    public function __construct(public string $userId, public array $topics)
    {
    }
}
