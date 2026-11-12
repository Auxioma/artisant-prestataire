<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Port;

/** Journal des alertes et relances envoyées : une même étape n'est jamais envoyée deux fois. */
interface ReminderLog
{
    /** Vrai si l'étape vient d'être enregistrée (à envoyer), faux si elle l'était déjà. */
    public function markSent(string $kind, string $subjectId, string $step, \DateTimeImmutable $at): bool;
}
