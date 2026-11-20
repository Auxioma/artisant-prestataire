<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Storage;

use App\Shared\Application\Integration\ExternalServiceUnavailable;
use App\Shared\Infrastructure\Storage\ClamAvScanner;
use PHPUnit\Framework\TestCase;

/** Protocole clamd INSTREAM simulé par une paire de sockets locale. */
final class ClamAvScannerTest extends TestCase
{
    /** @var resource|null */
    private $server;

    /** @return resource */
    private static function stream(string $content)
    {
        $stream = fopen('php://memory', 'w+');
        \assert(\is_resource($stream));
        fwrite($stream, $content);
        rewind($stream);

        return $stream;
    }

    private function scanner(string $reply): ClamAvScanner
    {
        $pair = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        \assert(\is_array($pair));
        [$client, $this->server] = $pair;
        fwrite($this->server, $reply);

        return new ClamAvScanner('tcp://inutilise:3310', static fn () => $client);
    }

    private function received(): string
    {
        \assert(\is_resource($this->server));
        stream_set_blocking($this->server, false);

        return (string) stream_get_contents($this->server);
    }

    public function testSendsLengthPrefixedChunksAndReportsCleanFile(): void
    {
        $scanner = $this->scanner("stream: OK\0");

        self::assertNull($scanner->scan(self::stream(str_repeat('a', 10_000))));
        self::assertSame("zINSTREAM\0".pack('N', 8192).str_repeat('a', 8192).pack('N', 1808).str_repeat('a', 1808).pack('N', 0), $this->received());
    }

    public function testReturnsSignature(): void
    {
        self::assertSame('Eicar-Test-Signature', $this->scanner("stream: Eicar-Test-Signature FOUND\0")->scan(self::stream('x')));
    }

    public function testUnexpectedReplyIsUnavailable(): void
    {
        $this->expectException(ExternalServiceUnavailable::class);
        $this->scanner("INSTREAM size limit exceeded. ERROR\0")->scan(self::stream('x'));
    }

    public function testUnreachableDaemonIsUnavailable(): void
    {
        $this->expectException(ExternalServiceUnavailable::class);
        new ClamAvScanner('tcp://127.0.0.1:1')->scan(self::stream('x'));
    }
}
