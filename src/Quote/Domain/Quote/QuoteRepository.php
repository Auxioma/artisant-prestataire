<?php

declare(strict_types=1);

namespace App\Quote\Domain\Quote;

interface QuoteRepository
{
    public function find(QuoteId $id): ?Quote;

    /** Verrou de ligne (`FOR UPDATE`) pour les transitions concurrentes. */
    public function findForUpdate(QuoteId $id): ?Quote;

    /** RG-101 d — le devis de cet artisan sur cette demande, s'il existe (unicité en base). */
    public function findByRequestAndArtisan(string $requestId, string $artisanId): ?Quote;

    /** Enregistre l'état complet (en-tête, versions, lignes) ; conflit de version optimiste → exception. */
    /** @return list<string> artisans ayant un devis actif (envoyé, consulté, accepté) sur la demande */
    public function artisansWithActiveQuote(string $requestId): array;

    /** @return list<string> devis envoyés ou consultés dont la validité est dépassée (les plus anciens d'abord) */
    public function expiredIds(\DateTimeImmutable $now, int $limit): array;

    public function save(Quote $quote): void;
}
