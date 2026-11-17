<?php

declare(strict_types=1);

namespace App\Billing\Application\Webhook;

interface WebhookVerifier
{
    /** @throws InvalidWebhook */
    public function verify(string $payload, string $signature): StripeEvent;
}
