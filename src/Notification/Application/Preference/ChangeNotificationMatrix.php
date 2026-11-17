<?php

declare(strict_types=1);

namespace App\Notification\Application\Preference;

use App\Shared\Application\Bus\Command;

/** C07.3 « Mes paramètres » : matrice sujet × canal (cases cochées = envoyé). */
final readonly class ChangeNotificationMatrix implements Command
{
    /** @param array<string, array<string, bool>> $matrix valeur de NotificationTopic → canal → envoyé */
    public function __construct(public string $userId, public array $matrix)
    {
    }
}
