<?php

declare(strict_types=1);

namespace App\Shared\Application\Admin;

use App\Shared\Application\Bus\Query;

/**
 * BO15.1 « Webhooks Stripe » — événements reçus, les plus récents d'abord, filtrables par statut (réponse :
 * array{total: int, rows: list<array{event_id: string, type: string, livemode: bool, status: string, attempts: int,
 * last_error: ?string, received_at: \DateTimeImmutable, processed_at: ?\DateTimeImmutable}>}). Le contenu de
 * l'événement n'est jamais renvoyé.
 */
final readonly class StripeWebhookLog implements Query
{
    public function __construct(public ?string $status = null, public int $offset = 0, public int $limit = 25)
    {
    }
}
