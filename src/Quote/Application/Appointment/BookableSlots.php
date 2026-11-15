<?php

declare(strict_types=1);

namespace App\Quote\Application\Appointment;

use App\Shared\Application\Bus\Query;

/**
 * C03.6 — créneaux proposables pour un devis accepté (agenda de l'artisan sur 30 jours, prévenance comprise pour le
 * client) ; réponse : BookableSlotsView, ou null si l'utilisateur n'est pas partie du devis ou le devis non accepté.
 */
final readonly class BookableSlots implements Query
{
    public const int DAYS = 30;

    public function __construct(public string $userId, public string $quoteId)
    {
    }
}
