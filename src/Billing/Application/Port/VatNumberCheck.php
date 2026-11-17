<?php

declare(strict_types=1);

namespace App\Billing\Application\Port;

/** Résultat d'une vérification VIES. `name` / `address` : null quand l'État membre ne les communique pas. */
final readonly class VatNumberCheck
{
    public function __construct(
        public string $vatNumber,
        public bool $valid,
        public ?string $name,
        public ?string $address,
        public \DateTimeImmutable $checkedAt,
    ) {
    }
}
