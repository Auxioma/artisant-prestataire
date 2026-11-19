<?php

declare(strict_types=1);

namespace App\Shared\Application\Security;

interface ImpersonationTickets
{
    /** Durée de validité d'un ticket : le temps de la redirection du back-office vers le site. */
    public const int TTL_SECONDS = 60;

    /** @return string jeton opaque à transmettre dans l'adresse de connexion */
    public function issue(ImpersonationTicket $ticket): string;

    /** Renvoie le ticket et l'invalide (usage unique), ou null s'il est inconnu ou expiré. */
    public function consume(string $token): ?ImpersonationTicket;
}
