<?php

declare(strict_types=1);

namespace App\Quote\Application\Client;

use App\Shared\Application\Bus\Query;

/**
 * RG-022bis — coordonnées du client et adresse complète d'intervention, pour l'artisan connecté dont le devis a été
 * accepté sur cette demande. Réponse : array{name, email, phone, address}|null (sinon rien n'est révélé).
 */
final readonly class AcceptedClientContact implements Query
{
    public function __construct(public string $userId, public string $requestId)
    {
    }
}
