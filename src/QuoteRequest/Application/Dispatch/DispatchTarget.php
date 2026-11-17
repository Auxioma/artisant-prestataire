<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Dispatch;

/** Artisan destinataire d'une demande : profil, compte (notifications) et fréquence de ses alertes. */
final readonly class DispatchTarget
{
    public function __construct(public string $artisanId, public string $userId, public AlertFrequency $frequency)
    {
    }
}
