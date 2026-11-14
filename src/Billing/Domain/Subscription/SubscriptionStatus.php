<?php

declare(strict_types=1);

namespace App\Billing\Domain\Subscription;

/**
 * §4.3 — cycle de l'abonnement d'un artisan. « En attente » tant que Stripe n'a pas confirmé le paiement (seul un
 * webhook active, jamais le retour navigateur) ; « en retard » après un échec de prélèvement (période de grâce) ;
 * « suspendu » au-delà de la grâce (envoi de devis bloqué, RG-106) ; « annulé » en fin de parcours.
 */
enum SubscriptionStatus: string
{
    case Incomplete = 'incomplete';
    case Active = 'active';
    case PastDue = 'past_due';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Incomplete => 'Paiement en cours de confirmation',
            self::Active => 'Actif',
            self::PastDue => 'En retard de paiement',
            self::Suspended => 'Suspendu',
            self::Cancelled => 'Résilié',
        };
    }

    /** Abonnement qui compte comme souscrit (un seul à la fois par artisan). */
    public function isLive(): bool
    {
        return \in_array($this, [self::Active, self::PastDue, self::Suspended], true);
    }
}
