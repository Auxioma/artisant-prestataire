<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Port;

interface SignupMailer
{
    /** E-20 — accusé de dépôt du dossier d'inscription. */
    public function sendSignupReceived(string $email, string $firstName, string $companyName, int $delayHours, int $freeCredits, string $userId): void;
}
