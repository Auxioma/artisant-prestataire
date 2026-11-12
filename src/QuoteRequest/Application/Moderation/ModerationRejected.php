<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Moderation;

/** Décision impossible (motif manquant, demande introuvable ou déjà traitée) ; message destiné à l'administrateur. */
final class ModerationRejected extends \DomainException
{
}
