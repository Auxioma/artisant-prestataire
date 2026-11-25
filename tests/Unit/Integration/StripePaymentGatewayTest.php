<?php

declare(strict_types=1);

namespace App\Tests\Unit\Integration;

use App\Billing\Domain\Plan\BillingInterval;
use App\Billing\Infrastructure\Payment\StripePaymentGateway;
use App\Shared\Application\Integration\ExternalServiceUnavailable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * T-602 — adaptateur Stripe : requêtes de la bibliothèque officielle passées par le client Symfony (anti-SSRF), clé
 * d'idempotence transmise, prix HT récurrent, erreurs converties ; aucun appel réseau (clé fictive).
 */
final class StripePaymentGatewayTest extends TestCase
{
    private const string KEY = 'cle-secrete-fictive';

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string}> */
    private array $requests = [];

    /** @param list<MockResponse> $responses */
    private function gateway(array $responses, string $key = self::KEY): StripePaymentGateway
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $headers = [];
            foreach (\is_array($options['headers'] ?? null) ? $options['headers'] : [] as $line) {
                if (\is_string($line) && str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }
            }
            $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => \is_string($options['body'] ?? null) ? $options['body'] : ''];

            return array_shift($responses) ?? new MockResponse('{}', ['http_code' => 500]);
        });

        return new StripePaymentGateway($client, $key);
    }

    public function testCustomerCreationCarriesIdempotencyKeyAndSecretKey(): void
    {
        $id = $this->gateway([new MockResponse('{"id":"cus_FICTIF1","object":"customer"}')])
            ->createCustomer('artisan.fictif@exemple.test', 'Artisan Fictif', ['artisan_id' => 'a-1'], 'cle-fictive-1');

        self::assertSame('cus_FICTIF1', $id);
        $request = $this->requests[0];
        self::assertSame(['POST', 'https://api.stripe.com/v1/customers'], [$request['method'], $request['url']]);
        self::assertSame('cle-fictive-1', $request['headers']['idempotency-key'] ?? null);
        self::assertSame('Bearer '.self::KEY, $request['headers']['authorization'] ?? null);
        parse_str($request['body'], $body);
        self::assertSame(['artisan.fictif@exemple.test', 'a-1', 'fr'], [$body['email'] ?? null, $body['metadata']['artisan_id'] ?? null, $body['preferred_locales'][0] ?? null]);
    }

    public function testRecurringPriceIsExclusiveOfTax(): void
    {
        $id = $this->gateway([new MockResponse('{"id":"price_FICTIF1","object":"price"}')])
            ->createPrice('prod_FICTIF1', 2900, BillingInterval::Year, ['plan_price_id' => 'p-1'], 'cle-fictive-2');

        self::assertSame('price_FICTIF1', $id);
        parse_str($this->requests[0]['body'], $body);
        self::assertSame(['prod_FICTIF1', 'eur', '2900', 'year', 'exclusive'], [$body['product'] ?? null, $body['currency'] ?? null, $body['unit_amount'] ?? null, $body['recurring']['interval'] ?? null, $body['tax_behavior'] ?? null]);
    }

    public function testExistingProductIsUpdatedInPlace(): void
    {
        $id = $this->gateway([new MockResponse('{"id":"prod_FICTIF1","object":"product"}')])
            ->saveProduct('prod_FICTIF1', 'Pro', null, false, ['plan_id' => 'x'], 'cle-fictive-3');

        self::assertSame('prod_FICTIF1', $id);
        self::assertSame('https://api.stripe.com/v1/products/prod_FICTIF1', $this->requests[0]['url']);
        parse_str($this->requests[0]['body'], $body);
        self::assertSame(['Pro', 'false'], [$body['name'] ?? null, $body['active'] ?? null]);
    }

    public function testStripeRefusalBecomesExternalServiceUnavailable(): void
    {
        $gateway = $this->gateway([new MockResponse('{"error":{"type":"invalid_request_error","code":"resource_missing","message":"No such product"}}', ['http_code' => 404])]);

        $this->expectException(ExternalServiceUnavailable::class);
        $gateway->deactivatePrice('price_INCONNU', 'cle-fictive-4');
    }

    public function testWithoutKeyNothingIsSent(): void
    {
        $gateway = $this->gateway([], '');
        self::assertFalse($gateway->isConfigured());
        self::assertNull($gateway->mode());

        try {
            $gateway->createCustomer('a@exemple.test', 'A', [], 'k');
            self::fail('appel sans clé');
        } catch (ExternalServiceUnavailable) {
            self::assertSame([], $this->requests);
        }
    }
}
