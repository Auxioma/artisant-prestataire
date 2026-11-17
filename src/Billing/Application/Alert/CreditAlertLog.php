<?php

declare(strict_types=1);

namespace App\Billing\Application\Alert;

/** RG-105 — journal des alertes de crédits envoyées : chaque seuil n'est signalé qu'une fois. */
interface CreditAlertLog
{
    /** Enregistre l'alerte ; false si elle l'a déjà été (rejeu de l'événement, envoi concurrent). */
    public function record(string $artisanId, string $kind, string $period, int $threshold, \DateTimeImmutable $at): bool;
}
