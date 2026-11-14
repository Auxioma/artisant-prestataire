<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Webhook;

use App\Billing\Application\Webhook\InvalidWebhook;
use App\Billing\Application\Webhook\StripeEvent;
use App\Billing\Application\Webhook\WebhookVerifier;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\Webhook;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** ADR-0010 — signature `Stripe-Signature` vérifiée par stripe-php (HMAC du corps brut, tolérance 300 s). */
final readonly class StripeWebhookVerifier implements WebhookVerifier
{
    private const int TOLERANCE_SECONDS = 300;

    public function __construct(#[Autowire('%env(default::STRIPE_WEBHOOK_SECRET)%')] private ?string $secret)
    {
    }

    public function verify(string $payload, string $signature): StripeEvent
    {
        if (null === $this->secret || '' === trim($this->secret)) {
            throw new InvalidWebhook('Secret de signature des webhooks non configuré.');
        }
        try {
            $event = Webhook::constructEvent($payload, $signature, $this->secret, self::TOLERANCE_SECONDS);
        } catch (SignatureVerificationException|UnexpectedValueException $e) {
            throw new InvalidWebhook($e->getMessage(), 0, $e);
        }
        $data = json_decode($payload, true, 64, \JSON_THROW_ON_ERROR);
        $inner = \is_array($data) && \is_array($data['data'] ?? null) ? $data['data'] : [];
        $object = [];
        foreach (\is_array($inner['object'] ?? null) ? $inner['object'] : [] as $key => $value) {
            $object[(string) $key] = $value;
        }

        return new StripeEvent((string) $event->id, (string) $event->type, (bool) $event->livemode, $object);
    }
}
