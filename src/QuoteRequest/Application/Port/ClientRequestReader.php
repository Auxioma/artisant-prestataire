<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Port;

use App\QuoteRequest\Application\Client\ClientRequestRow;
use App\QuoteRequest\Application\Client\ClientRequests;

/** C02 — lecture des demandes envoyées par un client (jamais celles d'un autre). */
interface ClientRequestReader
{
    /** @return array{items: list<ClientRequestRow>, total: int} */
    public function page(ClientRequests $query, \DateTimeImmutable $now): array;

    /** @return array{total: int, waiting: int, quoted: int, completed: int, active: int} */
    public function stats(string $clientId): array;

    /** @return list<string> photos analysées de la demande, si elle appartient au client */
    public function photos(string $clientId, string $requestId): array;
}
