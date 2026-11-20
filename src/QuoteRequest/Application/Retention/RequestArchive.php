<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Retention;

/** CDC §11 — archivage d'une demande : contenu libre, adresse et pièces jointes effacés ; numéro, métier, dates et statut conservés. */
interface RequestArchive
{
    public const string PLACEHOLDER = 'Demande archivée : son contenu a été effacé à l’issue de la durée de conservation.';

    /** @return list<string> demandes closes sans activité depuis `$before`, non archivées */
    public function archivableBefore(\DateTimeImmutable $before, int $limit): array;

    /** @return list<string> demandes non actives et non archivées d'un client */
    public function inactiveOfClient(string $clientId): array;

    /** @return list<string> fichiers des pièces jointes retirées, à détruire */
    public function archive(string $requestId, \DateTimeImmutable $now): array;
}
