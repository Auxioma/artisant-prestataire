<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Storage;

use App\Shared\Application\Integration\ExternalServiceUnavailable;
use App\Shared\Application\Storage\Port\AntivirusScanner;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Démon clamd, protocole INSTREAM (blocs préfixés par leur longueur, terminés par un bloc vide). */
final readonly class ClamAvScanner implements AntivirusScanner
{
    private const string SERVICE = 'clamav';
    private const int CHUNK = 8192;
    private const int TIMEOUT_SECONDS = 60;

    /** @var \Closure(): resource */
    private \Closure $connect;

    public function __construct(
        #[Autowire('%env(CLAMAV_DSN)%')]
        string $dsn,
        ?\Closure $connect = null,
    ) {
        $this->connect = $connect ?? static function () use ($dsn) {
            if (1 !== preg_match('#^(tcp|unix)://#', $dsn)) {
                throw new ExternalServiceUnavailable(self::SERVICE, 'CLAMAV_DSN invalide');
            }
            $socket = @stream_socket_client($dsn, $code, $message, 5);
            if (false === $socket) {
                throw new ExternalServiceUnavailable(self::SERVICE, \sprintf('connexion impossible (%s)', $message));
            }
            stream_set_timeout($socket, self::TIMEOUT_SECONDS);

            return $socket;
        };
    }

    public function scan($stream): ?string
    {
        $socket = ($this->connect)();
        if (!\is_resource($socket)) {
            throw new ExternalServiceUnavailable(self::SERVICE, 'connexion impossible');
        }
        try {
            self::send($socket, "zINSTREAM\0");
            while (!feof($stream)) {
                $chunk = fread($stream, self::CHUNK);
                if (false === $chunk) {
                    throw new ExternalServiceUnavailable(self::SERVICE, 'lecture du fichier impossible');
                }
                if ('' !== $chunk) {
                    self::send($socket, pack('N', \strlen($chunk)).$chunk);
                }
            }
            self::send($socket, pack('N', 0));

            $reply = '';
            while (!feof($socket) && !str_contains($reply, "\0")) {
                $read = fread($socket, 1024);
                if (false === $read || ('' === $read && true === stream_get_meta_data($socket)['timed_out'])) {
                    throw new ExternalServiceUnavailable(self::SERVICE, 'délai de réponse dépassé');
                }
                $reply .= $read;
            }
        } finally {
            fclose($socket);
        }

        $reply = trim(strtok($reply, "\0") ?: $reply);
        if (1 === preg_match('/^stream: OK$/', $reply)) {
            return null;
        }
        if (1 === preg_match('/^stream: (.+) FOUND$/', $reply, $m)) {
            return $m[1];
        }

        throw new ExternalServiceUnavailable(self::SERVICE, 'réponse inattendue : '.mb_substr($reply, 0, 120));
    }

    /** @param resource $socket */
    private static function send($socket, string $data): void
    {
        $length = \strlen($data);
        for ($written = 0; $written < $length; $written += $bytes) {
            $bytes = fwrite($socket, substr($data, $written));
            if (false === $bytes || 0 === $bytes) {
                throw new ExternalServiceUnavailable(self::SERVICE, 'envoi interrompu');
            }
        }
    }
}
