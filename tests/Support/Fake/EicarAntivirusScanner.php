<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake;

use App\Shared\Application\Integration\ExternalServiceUnavailable;
use App\Shared\Application\Storage\Port\AntivirusScanner;

/** Antivirus de test : signale le fichier de test standard EICAR ; `$down` simule un démon arrêté. */
final class EicarAntivirusScanner implements AntivirusScanner
{
    /** Chaîne EICAR reconstituée à l'exécution (le dépôt ne contient pas le fichier de test lui-même). */
    public const string EICAR_HEAD = 'X5O!P%@AP[4\PZX54(P^)7CC)7}$';

    public static bool $down = false;
    public static int $scans = 0;

    public static function eicar(): string
    {
        return self::EICAR_HEAD.'EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';
    }

    public function scan($stream): ?string
    {
        if (self::$down) {
            throw new ExternalServiceUnavailable('clamav', 'arrêté (test)');
        }
        ++self::$scans;

        return str_contains((string) stream_get_contents($stream), self::eicar()) ? 'Eicar-Test-Signature' : null;
    }
}
