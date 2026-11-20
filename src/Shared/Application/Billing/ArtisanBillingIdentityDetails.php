<?php

declare(strict_types=1);

namespace App\Shared\Application\Billing;

final readonly class ArtisanBillingIdentityDetails
{
    public function __construct(
        public ?string $companyName,
        public ?string $street,
        public ?string $postalCode,
        public ?string $city,
        public ?string $vatNumber,
    ) {
    }
}
