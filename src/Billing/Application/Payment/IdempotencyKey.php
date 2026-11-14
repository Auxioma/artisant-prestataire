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

    /** Identifiant au format UUID (version 5, variante RFC) dérivé d'une graine : même opération → même identifiant. */
    public static function uuid(string $seed): string
    {
        $h = hash('sha256', $seed);

        return \sprintf('%s-%s-5%s-%x%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 13, 3), 8 | (hexdec($h[16]) & 3), substr($h, 17, 3), substr($h, 20, 12));
    }
}
