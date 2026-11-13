<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Client;

use App\Shared\Application\Bus\Query;

/** C02 — demandes du client : groupe (en cours, terminées, expirées / annulées, brouillons), recherche, période. */
final readonly class ClientRequests implements Query
{
    public const array GROUPS = ['en-cours' => 'En cours', 'terminees' => 'Terminées', 'closes' => 'Expirées ou annulées', 'brouillons' => 'Brouillons'];
    public const array PERIODS = ['7' => '7 derniers jours', '30' => '30 derniers jours', '365' => '12 derniers mois'];

    public function __construct(public string $clientId, public ?string $group = null, public string $search = '', public ?int $sinceDays = null, public int $page = 1, public int $perPage = 10)
    {
    }
}
