<?php

declare(strict_types=1);

namespace App\Tests\Unit\Integration;

use App\Identity\Infrastructure\Turnstile\TurnstileHumanVerification;
use App\Shared\Application\Integration\ExternalServiceUnavailable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Turnstile : validation serveur, action attendue, distinction échec du visiteur / panne du fournisseur. */
final class TurnstileHumanVerificationTest extends TestCase
{
    private static function verifier(MockHttpClient $client, string $secret = 'secret-de-test'): TurnstileHumanVerification
    {
        return new TurnstileHumanVerification($client, $secret);
    }

    public function testSendsSecretTokenAndIpToSiteverify(): void
    {
        $client = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://turnstile.test/siteverify', $url);
            self::assertSame('secret=secret-de-test&response=jeton&remoteip=203.0.113.7', $options['body']);

            return new MockResponse('{"success":true,"error-codes":[],"action":"signup"}');
        }, 'https://turnstile.test/');

        self::assertTrue(self::verifier($client)->verify(' jeton ', '203.0.113.7', 'signup'));
    }

    public function testRejectedTokenOrWrongActionFails(): void
    {
        $client = new MockHttpClient([
            new MockResponse('{"success":false,"error-codes":["invalid-input-response"]}'),
            new MockResponse('{"success":true,"error-codes":[],"action":"login"}'),
        ]);
        $verifier = self::verifier($client);

        self::assertFalse($verifier->verify('jeton'));
        self::assertFalse($verifier->verify('jeton', null, 'signup'));
    }

    public function testEmptyOrOversizedTokenFailsWithoutCallAndInvalidIpIsDropped(): void
    {
        $client = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            self::assertIsString($options['body']);
            self::assertStringNotContainsString('remoteip', $options['body']);

            return new MockResponse('{"success":true}');
        });
        $verifier = self::verifier($client);

        self::assertFalse($verifier->verify('  '));
        self::assertFalse($verifier->verify(str_repeat('a', 2049)));
        self::assertSame(0, $client->getRequestsCount());
        self::assertTrue($verifier->verify('jeton', 'pas-une-ip'));
    }

    public function testProviderErrorsAreNotTreatedAsVisitorFailures(): void
    {
        $client = new MockHttpClient(new MockResponse('{"success":false,"error-codes":["invalid-input-secret"]}'));

        $this->expectException(ExternalServiceUnavailable::class);
        self::verifier($client)->verify('jeton');
    }

    public function testMissingSecretIsUnavailable(): void
    {
        $this->expectException(ExternalServiceUnavailable::class);
        self::verifier(new MockHttpClient([]), '')->verify('jeton');
    }
}
