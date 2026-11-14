<?php

declare(strict_types=1);

namespace App\Billing\Application\Subscription;

/** Action d'abonnement refusée (aucun appel au prestataire) ; message destiné à l'artisan. */
final class SubscriptionChangeRejected extends \DomainException
{
}
