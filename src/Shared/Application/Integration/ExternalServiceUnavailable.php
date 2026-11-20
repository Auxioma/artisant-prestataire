<?php

declare(strict_types=1);

namespace App\Shared\Application\Integration;

/**
 * Service externe injoignable, en erreur ou non configuré (Sirene, géocodage, VIES, Turnstile…). L'appelant choisit
 * la conduite à tenir (vérification manuelle, nouvelle tentative, refus) : un adaptateur ne décide jamais à sa place.
 */
final class ExternalServiceUnavailable extends \RuntimeException
{
    public function __construct(public readonly string $service, string $reason, ?\Throwable $previous = null)
    {
        parent::__construct(\sprintf('Service externe « %s » indisponible : %s', $service, $reason), 0, $previous);
    }
}
