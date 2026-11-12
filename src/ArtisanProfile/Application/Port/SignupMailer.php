<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Port;

interface SignupMailer
{
    /** E-20 — accusé de dépôt du dossier d'inscription. */
    public function sendSignupReceived(string $email, string $firstName, string $companyName, int $delayHours, int $freeCredits, string $userId): void;

    /** E-21 — compte vérifié (crédits offerts : RG-044). */
    public function sendVerified(string $email, string $firstName, string $companyName, int $freeCredits, string $userId): void;

    /** E-22 — dossier refusé (`$refused`, motif normalisé) ou complément demandé (`$message`). */
    public function sendRejectedOrMoreInfo(string $email, string $firstName, string $companyName, bool $refused, ?string $reasonLabel, ?string $message, string $userId): void;

    /** E-24 — justificatif validé ou refusé. */
    public function sendDocumentReviewed(string $email, string $firstName, string $documentLabel, bool $approved, ?string $reason, string $userId): void;
}
