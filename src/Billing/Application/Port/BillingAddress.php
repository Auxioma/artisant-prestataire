<?php

declare(strict_types=1);

namespace App\Billing\Application\Port;

/** Coordonnées de facturation transmises au prestataire (factures, calcul de la TVA). */
final readonly class BillingAddress
{
    public function __construct(
        public string $name,
        public string $street,
        public string $postalCode,
        public string $city,
        public string $country,
        public ?string $vatNumber,
    ) {
    }
}
