<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use App\Shared\Infrastructure\Storage\ClamAvScanner;
use App\Tests\Support\Fake\EicarAntivirusScanner;
use PHPUnit\Framework\TestCase;

/** Démon ClamAV réel (conteneur Docker) : fichier de test EICAR détecté, fichier ordinaire accepté. */
final class ClamAvDaemonTest extends TestCase
{
    public function testDetectsEicarWithTheRealDaemon(): void
    {
        $dsn = $_SERVER['CLAMAV_DSN'] ?? $_ENV['CLAMAV_DSN'] ?? '';
        \assert(\is_string($dsn));
        $probe = '' === $dsn ? false : @stream_socket_client($dsn, $code, $message, 2);
        if (false === $probe) {
            self::markTestSkipped('Démon ClamAV injoignable ('.$dsn.') : lancer la pile Docker.');
        }
        fclose($probe);
        $scanner = new ClamAvScanner($dsn);

        $eicar = fopen('php://memory', 'w+');
        $clean = fopen('php://memory', 'w+');
        \assert(\is_resource($eicar) && \is_resource($clean));
        fwrite($eicar, EicarAntivirusScanner::eicar());
        fwrite($clean, str_repeat('Attestation fictive ', 1000));
        rewind($eicar);
        rewind($clean);

        self::assertStringContainsString('Eicar', (string) $scanner->scan($eicar));
        self::assertNull($scanner->scan($clean));
    }
}
