<?php

declare(strict_types=1);

namespace App\Billing\Application\Webhook;

use App\Shared\Application\Bus\Command;

/** Traitement asynchrone d'un événement Stripe reçu (transport `stripe_webhooks`, reprises exponentielles). */
final readonly class HandleStripeEvent implements Command
{
    public function __construct(public string $eventId)
    {
    }
}
