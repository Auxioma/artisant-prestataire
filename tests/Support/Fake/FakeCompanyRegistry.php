<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake;

use App\Shared\Application\Integration\CompanyRecord;
use App\Shared\Application\Integration\CompanyRegistry;
use App\Shared\Application\Integration\ExternalServiceUnavailable;
use App\Shared\Domain\ValueObject\Siret;

/** Répertoire Sirene de test (SIRET fictifs, clés de Luhn valides). */
final class FakeCompanyRegistry implements CompanyRegistry
{
    public const string ACTIVE = '90000000100017';
    /** Deux autres entreprises actives (banc de concurrence : plusieurs artisans). */
    public const string ACTIVE_BIS = '90000004300001';
    public const string ACTIVE_TER = '90000005100004';
    public const string CLOSED = '90000001900019';
    public const string UNKNOWN = '90000002700012';
    public const string UNAVAILABLE = '90000003500015';

    public function find(Siret $siret): ?CompanyRecord
    {
        return match ($siret->value) {
            self::ACTIVE => new CompanyRecord(self::ACTIVE, 'PLOMBERIE FICTIVE DE TEST', true, true, '43.22A', '5499', '12 RUE DE L EXEMPLE', '14000', 'CAEN', '14118', new \DateTimeImmutable('2019-03-01')),
            self::ACTIVE_BIS => new CompanyRecord(self::ACTIVE_BIS, 'CHAUFFAGE FICTIF DE TEST', true, true, '43.22A', '5499', '3 RUE DE L EXEMPLE', '76600', 'LE HAVRE', '76351', new \DateTimeImmutable('2020-05-01')),
            self::ACTIVE_TER => new CompanyRecord(self::ACTIVE_TER, 'SANITAIRES FICTIFS DE TEST', true, true, '43.22A', '5499', '5 RUE DE L EXEMPLE', '76600', 'LE HAVRE', '76351', new \DateTimeImmutable('2021-09-01')),
            self::CLOSED => new CompanyRecord(self::CLOSED, 'ANCIENNE ENTREPRISE FICTIVE', false, true, '43.22A', '5499', null, '76600', 'LE HAVRE', '76351', null),
            self::UNAVAILABLE => throw new ExternalServiceUnavailable('sirene', 'arrêté (test)'),
            default => null,
        };
    }
}
