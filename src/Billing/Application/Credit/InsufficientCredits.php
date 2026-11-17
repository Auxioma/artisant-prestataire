<?php

declare(strict_types=1);

namespace App\Billing\Application\Credit;

/** RG-106 — plus aucun crédit : l'envoi est bloqué (le reste de l'espace reste accessible). */
final class InsufficientCredits extends \DomainException
{
}
