<?php

declare(strict_types=1);

namespace App\QuoteRequest\Domain\Request;

/** Règle de gestion d'une demande enfreinte (RG-064 à RG-066, RG-070) ; le message est destiné au client. */
final class RequestRuleViolation extends \DomainException
{
}
