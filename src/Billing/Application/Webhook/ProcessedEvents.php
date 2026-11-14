<?php

declare(strict_types=1);

namespace App\Billing\Application\Webhook;

/** Journal des événements Stripe (`billing.processed_stripe_event`) : réception unique, état du traitement. */
interface ProcessedEvents
{
    /** Vrai si l'événement est nouveau ; faux s'il a déjà été reçu (rejeu, renvoi de Stripe). */
    public function record(StripeEvent $event, string $payloadSha256, \DateTimeImmutable $at): bool;

    public function find(string $eventId): ?StripeEvent;

    public function isDone(string $eventId): bool;

    /** Traité (ou ignoré : type non géré, objet étranger à la plateforme) ; les échecs restent « received » et sont repris. */
    public function markProcessed(string $eventId, bool $ignored, \DateTimeImmutable $at): void;
}
