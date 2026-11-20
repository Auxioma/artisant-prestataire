<?php

declare(strict_types=1);

namespace App\Tests\Unit\Integration;

use App\Billing\Application\Webhook\InvalidWebhook;
use App\Billing\Infrastructure\Webhook\StripeWebhookVerifier;
use PHPUnit\Framework\TestCase;

/** ADR-0010 : signature HMAC du corps brut, tolérance de 300 s, aucun secret = aucun événement accepté. */
final class StripeWebhookVerifierTest extends TestCase
{
    private const string SECRET = 'secret-webhook-fictif';
    private const string PAYLOAD = '{"id":"evt_FICTIF","object":"event","type":"invoice.paid","livemode":false,"data":{"object":{"id":"in_FICTIF","amount_paid":2900}}}';

    public static function sign(string $payload, string $secret, ?int $at = null): string
    {
        $at ??= time();

        return 't='.$at.',v1='.hash_hmac('sha256', $at.'.'.$payload, $secret);
    }

    public function testValidSignatureGivesTheEvent(): void
    {
        $event = new StripeWebhookVerifier(self::SECRET)->verify(self::PAYLOAD, self::sign(self::PAYLOAD, self::SECRET));

        self::assertSame(['evt_FICTIF', 'invoice.paid', false, 'in_FICTIF', 2900], [$event->id, $event->type, $event->livemode, $event->string('id'), $event->int('amount_paid')]);
    }

    public function testForgedOrStaleSignatureIsRejected(): void
    {
        foreach ([self::sign(self::PAYLOAD, 'autre-secret'), self::sign(self::PAYLOAD, self::SECRET, time() - 3600), 'n’importe quoi'] as $signature) {
            try {
                new StripeWebhookVerifier(self::SECRET)->verify(self::PAYLOAD, $signature);
                self::fail('signature acceptée : '.$signature);
            } catch (InvalidWebhook) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testWithoutSecretNothingIsAccepted(): void
    {
        $this->expectException(InvalidWebhook::class);
        new StripeWebhookVerifier('')->verify(self::PAYLOAD, self::sign(self::PAYLOAD, ''));
    }
}
