<?php

declare(strict_types=1);

namespace App\Shared\Application\Identity;

final readonly class AccountReviewDetails
{
    public function __construct(
        public string $firstName,
        public string $lastName,
        public string $email,
        public bool $emailVerified,
        /** Autres comptes portant le même numéro de téléphone. */
        public int $samePhoneAccounts,
        /** Autres comptes vus depuis un même appareil (identifiant aléatoire `tm_device`, aucune empreinte navigateur). */
        public int $sameDeviceAccounts,
    ) {
    }
}
