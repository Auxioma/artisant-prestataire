<?php

declare(strict_types=1);

namespace App\Billing\Application\Payment;

/**
 * ADR-0010 — clé d'idempotence déterministe des requêtes mutantes vers le prestataire de paiement :
 * `sha256(commande + agrégat + intention)`. Une nouvelle tentative de la même opération réutilise la même clé (Stripe
 * renvoie alors la première réponse pendant 24 h) ; une opération différente (autre contenu) change d'intention.
 */
final class IdempotencyKey
{
    public static function for(string $command, string $aggregateId, string $intent): string
    {
        return hash('sha256', $command."\n".$aggregateId."\n".$intent);
    }
}
