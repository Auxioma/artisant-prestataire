<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake;

use App\Billing\Application\Port\VatNumberCheck;
use App\Billing\Application\Port\VatNumberChecker;
use App\Shared\Application\Integration\ExternalServiceUnavailable;

/** VIES de test (aucun appel réseau) : numéro contenant « 999 » invalide, « 000 » indisponible, valide sinon. */
final class FakeVatNumberChecker implements VatNumberChecker
{
    public function check(string $vatNumber): VatNumberCheck
    {
        $normalized = strtoupper(preg_replace('/\s+/', '', $vatNumber) ?? '');
        if (str_contains($normalized, '000')) {
            throw new ExternalServiceUnavailable('vies', 'indisponibilité simulée');
        }

        return new VatNumberCheck($normalized, !str_contains($normalized, '999'), null, null, new \DateTimeImmutable());
    }
}
