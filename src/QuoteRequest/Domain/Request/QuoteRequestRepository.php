<?php

declare(strict_types=1);

namespace App\QuoteRequest\Domain\Request;

interface QuoteRequestRepository
{
    public function find(QuoteRequestId $id): ?QuoteRequest;

    /** RG-101 / RG-072 / RG-103 — lecture verrouillée (`SELECT … FOR UPDATE`) dans la transaction en cours. */
    public function findForUpdate(QuoteRequestId $id): ?QuoteRequest;

    public function add(QuoteRequest $request): void;

    /** Numéro lisible, unique et croissant (C02 « n° de demande »), par exemple DM-2026-000042. */
    public function nextNumber(\DateTimeImmutable $now): string;

    /** RG-065 — sérialise les envois d'un même client jusqu'à la fin de la transaction (plafond et anti-doublon). */
    public function lockClient(string $clientId): void;

    /** RG-065 — demandes du client dans un statut RequestStatus::ACTIVE. */
    public function countActiveOfClient(string $clientId): int;

    /** RG-065 — une demande de même empreinte (métier + adresse) envoyée depuis `$since`, hors annulées et refusées. */
    public function hasDuplicateSince(DuplicateKey $key, \DateTimeImmutable $since): bool;

    /**
     * RG-090 : demandes du client encore actives (RequestStatus::ACTIVE).
     *
     * @return list<QuoteRequestId>
     */
    public function activeOfClient(string $clientId): array;
}
