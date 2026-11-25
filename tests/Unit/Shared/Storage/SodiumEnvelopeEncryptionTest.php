<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Storage;

use App\Shared\Application\Storage\WrappedKey;
use App\Shared\Infrastructure\Storage\SodiumEnvelopeEncryption;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** ADR-0008 : chiffrement enveloppe — aller-retour, intégrité, troncature, rotation des clés maîtresses. */
final class SodiumEnvelopeEncryptionTest extends TestCase
{
    private static function kek(): string
    {
        return base64_encode(random_bytes(32));
    }

    /** @return resource */
    private static function stream(string $content = '')
    {
        $stream = fopen('php://memory', 'w+');
        \assert(\is_resource($stream));
        fwrite($stream, $content);
        rewind($stream);

        return $stream;
    }

    /** @return array{string, WrappedKey} */
    private static function encrypt(SodiumEnvelopeEncryption $crypto, string $plain): array
    {
        $out = self::stream();
        $key = $crypto->encrypt(self::stream($plain), $out);
        rewind($out);

        return [(string) stream_get_contents($out), $key];
    }

    private static function decrypt(SodiumEnvelopeEncryption $crypto, string $cipher, WrappedKey $key): string
    {
        $out = self::stream();
        $crypto->decrypt(self::stream($cipher), $out, $key);
        rewind($out);

        return (string) stream_get_contents($out);
    }

    /** @return iterable<string, array{int}> */
    public static function sizes(): iterable
    {
        foreach ([0, 1, 65_535, 65_536, 65_537, 200_000] as $size) {
            yield $size.' octets' => [$size];
        }
    }

    #[DataProvider('sizes')]
    public function testRoundTrip(int $size): void
    {
        $crypto = new SodiumEnvelopeEncryption('k1:'.self::kek(), 'k1', 'test');
        $plain = str_repeat('Kbis fictif ', intdiv($size, 12) + 1);
        $plain = substr($plain, 0, $size);

        [$cipher, $key] = self::encrypt($crypto, $plain);

        self::assertSame('k1', $key->kekId);
        if ($size > 12) {
            self::assertStringNotContainsString('Kbis fictif', $cipher);
        }
        self::assertSame($plain, self::decrypt($crypto, $cipher, $key));
    }

    public function testEachFileGetsItsOwnDataKey(): void
    {
        $crypto = new SodiumEnvelopeEncryption('k1:'.self::kek(), 'k1', 'test');

        self::assertNotSame(self::encrypt($crypto, 'identique')[1]->wrappedDek, self::encrypt($crypto, 'identique')[1]->wrappedDek);
    }

    /** @return iterable<string, array{\Closure(string): string}> */
    public static function corruptions(): iterable
    {
        yield 'octet modifié' => [static fn (string $c): string => substr_replace($c, \chr(\ord($c[30]) ^ 1), 30, 1)];
        yield 'dernier bloc retiré' => [static fn (string $c): string => substr($c, 0, 24 + 65_536 + 17)];
        yield 'données ajoutées' => [static fn (string $c): string => $c.'x'];
        yield 'vide' => [static fn (string $c): string => ''];
    }

    /** @param \Closure(string): string $corrupt */
    #[DataProvider('corruptions')]
    public function testTamperingIsDetected(\Closure $corrupt): void
    {
        $crypto = new SodiumEnvelopeEncryption('k1:'.self::kek(), 'k1', 'test');
        [$cipher, $key] = self::encrypt($crypto, str_repeat('a', 100_000));

        $this->expectException(\RuntimeException::class);
        self::decrypt($crypto, $corrupt($cipher), $key);
    }

    public function testRotationKeepsOldFilesReadable(): void
    {
        $old = self::kek();
        [$cipher, $key] = self::encrypt(new SodiumEnvelopeEncryption('k1:'.$old, 'k1', 'test'), 'ancien');

        $rotated = new SodiumEnvelopeEncryption('k1:'.$old.';k2:'.self::kek(), 'k2', 'test');

        self::assertSame('ancien', self::decrypt($rotated, $cipher, $key));
        self::assertSame('k2', self::encrypt($rotated, 'nouveau')[1]->kekId);
    }

    public function testUnknownOrWrongMasterKeyFails(): void
    {
        [$cipher, $key] = self::encrypt(new SodiumEnvelopeEncryption('k1:'.self::kek(), 'k1', 'test'), 'secret');

        try {
            self::decrypt(new SodiumEnvelopeEncryption('k2:'.self::kek(), 'k2', 'test'), $cipher, $key);
            self::fail('Clé inconnue acceptée');
        } catch (\RuntimeException) {
        }
        $this->expectException(\RuntimeException::class);
        self::decrypt(new SodiumEnvelopeEncryption('k1:'.self::kek(), 'k1', 'test'), $cipher, $key);
    }

    public function testInvalidConfigurationAndDevelopmentKeyInProduction(): void
    {
        try {
            new SodiumEnvelopeEncryption('k1:'.base64_encode('court'), 'k1', 'test');
            self::fail('Clé de 5 octets acceptée');
        } catch (\InvalidArgumentException) {
        }

        $this->expectExceptionMessage('production');
        self::encrypt(new SodiumEnvelopeEncryption('dev-1:'.base64_encode(str_repeat("\0", 32)), 'dev-1', 'prod'), 'x');
    }
}
