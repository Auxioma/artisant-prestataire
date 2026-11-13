<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Port;

use App\QuoteRequest\Application\Pro\IgnoreReason;
use App\QuoteRequest\Application\Pro\ProArtisan;
use App\QuoteRequest\Application\Pro\ProRequestCard;
use App\QuoteRequest\Application\Pro\ProRequestFilter;

/**
 * Espace pro « Demandes » (PR02 / PR03). Artisan vérifié : demandes reçues (`request_dispatch`), ouvertes, ou déjà
 * ouvertes par lui et devenues indisponibles (RG-111). Artisan en attente de vérification : aperçu Q15 des demandes
 * ouvertes compatibles avec ses métiers et sa zone.
 */
interface ProRequestReader
{
    /** Profil vérifié ou en attente de vérification de ce compte ; null sinon. */
    public function artisanOf(string $userId): ?ProArtisan;

    /** @return array{items: list<ProRequestCard>, total: int} */
    public function page(ProArtisan $artisan, ProRequestFilter $filter, int $page, int $perPage, \DateTimeImmutable $now): array;

    /** @return array{unread: int, urgent: int} non lues, urgentes encore ouvertes (hors ignorées) */
    public function stats(ProArtisan $artisan): array;

    /** Carte d'une demande accessible à l'artisan ; null s'il n'y a pas accès. */
    public function card(ProArtisan $artisan, string $requestId): ?ProRequestCard;

    /** @return list<string> photos de la demande, disponibles après analyse antivirus */
    public function photos(string $requestId): array;

    public function markViewed(string $artisanId, string $requestId, \DateTimeImmutable $at): void;

    /** `null` en `$reason` et `$restore` : remet la demande dans la liste. */
    public function ignore(string $artisanId, string $requestId, ?IgnoreReason $reason, bool $restore, \DateTimeImmutable $at): bool;
}
