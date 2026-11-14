<?php

declare(strict_types=1);

namespace App\Billing\Application\Port;

/** Code promo actif chez le prestataire (§4.3) : identifiant à passer au paiement, remise lisible (« −20 % pendant 3 mois »). */
final readonly class PromotionCode
{
    public function __construct(public string $id, public string $code, public string $label)
    {
    }
}
