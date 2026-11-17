<?php

declare(strict_types=1);

namespace App\Identity\Application\Port;

use App\Identity\Domain\Consent\CookieConsent;

interface ConsentLog
{
    /**
     * Ajoute la preuve du consentement (journal append-only). L'IP est déjà hachée par l'appelant.
     */
    public function record(string $id, string $visitorId, ?string $userId, CookieConsent $consent, ?string $ipHash): void;
}
