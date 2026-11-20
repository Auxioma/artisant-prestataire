<?php

declare(strict_types=1);

namespace App\Tests\Unit\Integration;

use App\Billing\Infrastructure\Vies\ViesVatNumberChecker;
use App\Shared\Application\Integration\ExternalServiceUnavailable;
use App\Tests\Support\Fake\FixedClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** ADR-0009 : vérification VIES ; format et clé française contrôlés localement, indisponibilités signalées. */
final class ViesVatNumberCheckerTest extends TestCase
{
    private const string VAT = 'FR68900000001'; // fictif : clé calculée sur le SIREN 900000001

    private static function checker(MockHttpClient $client): ViesVatNumberChecker
    {
        return new ViesVatNumberChecker($client, new FixedClock(new \DateTimeImmutable('2026-11-11T10:00:00Z')));
    }

    public function testValidNumberWithTraderDetails(): void
    {
        $client = new MockHttpClient(static function (string $method, string $url): MockResponse {
            self::assertSame('https://vies.test/ms/FR/vat/68900000001', $url);

            return new MockResponse('{"isValid":true,"userError":"VALID","name":"PLOMBERIE FICTIVE DE TEST","address":"12 RUE DE L EXEMPLE 14000 CAEN","vatNumber":"68900000001"}');
        }, 'https://vies.test/');

        $check = self::checker($client)->check('fr 68 900 000 001');

        self::assertTrue($check->valid);
        self::assertSame(self::VAT, $check->vatNumber);
        self::assertSame('PLOMBERIE FICTIVE DE TEST', $check->name);
        self::assertSame('2026-11-11', $check->checkedAt->format('Y-m-d'));
    }

    public function testInvalidNumberHidesPlaceholders(): void
    {
        $client = new MockHttpClient(new MockResponse('{"isValid":false,"userError":"INVALID","name":"---","address":"---"}'));

        $check = self::checker($client)->check(self::VAT);

        self::assertFalse($check->valid);
        self::assertNull($check->name);
        self::assertNull($check->address);
    }

    /** @return iterable<string, array{string}> */
    public static function malformed(): iterable
    {
        yield 'pays hors UE' => ['US123456789'];
        yield 'trop court' => ['DE1'];
        yield 'caractères interdits' => ['DE12345678<'];
        yield 'clé française fausse' => ['FR00900000001'];
        yield 'vide' => [''];
    }

    #[DataProvider('malformed')]
    public function testMalformedNumbersAreRejectedWithoutCall(string $number): void
    {
        $client = new MockHttpClient([]);

        self::assertFalse(self::checker($client)->check($number)->valid);
        self::assertSame(0, $client->getRequestsCount());
    }

    public function testGreekPrefixIsNormalised(): void
    {
        $client = new MockHttpClient(static function (string $method, string $url): MockResponse {
            self::assertStringEndsWith('/ms/EL/vat/123456789', $url);

            return new MockResponse('{"isValid":true,"userError":"VALID","name":"---","address":"---"}');
        }, 'https://vies.test/');

        self::assertSame('EL123456789', self::checker($client)->check('GR123456789')->vatNumber);
    }

    public function testMemberStateOutageIsUnavailable(): void
    {
        $client = new MockHttpClient(new MockResponse('{"isValid":false,"userError":"MS_UNAVAILABLE"}'));

        $this->expectException(ExternalServiceUnavailable::class);
        $this->expectExceptionMessage('MS_UNAVAILABLE');
        self::checker($client)->check(self::VAT);
    }
}
