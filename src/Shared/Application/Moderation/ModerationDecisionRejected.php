<?php

declare(strict_types=1);

namespace App\Shared\Application\Moderation;

/** Décision de modération impossible (élément introuvable ou déjà traité, motif manquant) ; message pour l'administrateur. */
final class ModerationDecisionRejected extends \DomainException
{
}
