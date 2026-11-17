<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Pro;

/**
 * Artisan connecté, vu de l'espace pro « Demandes » : vérifié (répond aux demandes reçues) ou en attente de
 * vérification (aperçu Q15 en lecture seule des demandes compatibles).
 */
final readonly class ProArtisan
{
    /**
     * @param array<string, string> $jobs        métiers exercés (id => nom)
     * @param string|null           $centerInsee commune de départ (distance, RG-022)
     */
    public function __construct(public string $artisanId, public string $userId, public bool $verified, public array $jobs, public ?string $centerInsee = null)
    {
    }
}
