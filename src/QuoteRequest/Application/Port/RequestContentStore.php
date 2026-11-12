<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Port;

/** Contenu d'une demande envoyée hors agrégat : adresse scellée (RG-022bis) et pièces jointes (RG-061). */
interface RequestContentStore
{
    /** L'adresse complète est scellée ; le point géocodé sert au calcul des distances, jamais exposé. */
    public function saveAddress(string $requestId, string $label, ?float $latitude, ?float $longitude, \DateTimeImmutable $now): void;

    /**
     * @param list<array{file_id: string, name: string}> $files dans l'ordre d'affichage
     */
    public function attachFiles(string $requestId, array $files): void;

    public function address(string $requestId): ?string;
}
