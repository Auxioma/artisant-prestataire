<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Maintenance;

use App\Shared\Application\Bus\Command;

/** E-23 — relances J+2 / J+7 des dossiers inachevés ou envoyés avec des pièces obligatoires manquantes. Tâche quotidienne, idempotente. */
final readonly class SendSignupReminders implements Command
{
}
