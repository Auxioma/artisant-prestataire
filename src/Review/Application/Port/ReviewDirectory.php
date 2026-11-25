<?php

declare(strict_types=1);

namespace App\Review\Application\Port;

/** Lectures hors du module : devis accepté d'une demande, prestation, compte de l'artisan. */
interface ReviewDirectory
{
    /**
     * Devis accepté de la demande du client et date de prestation connue (dernier rendez-vous réalisé ou confirmé et
     * commencé), ou null si le client n'a accepté aucun devis sur cette demande.
     *
     * @return array{quote_id: string, artisan_id: string, accepted_at: \DateTimeImmutable, service_at: ?\DateTimeImmutable}|null
     */
    public function acceptedQuote(string $clientUserId, string $requestId): ?array;

    public function artisanOfUser(string $userId): ?string;

    /**
     * Parties d'un avis pour les notifications (E-18, E-37, E-38).
     *
     * @return array{request_id: string, request_number: string, client_user_id: string, client_email: ?string, client_first_name: ?string, artisan_user_id: string, artisan_email: ?string, artisan_first_name: ?string, artisan_name: string, rating: int}|null
     */
    public function parties(string $reviewId): ?array;

    /**
     * Note moyenne et nombre d'avis publiés de l'artisan (RG-012, RG-024).
     *
     * @return array{avg: ?float, count: int}
     */
    public function publishedStats(string $artisanId): array;

    /**
     * E-16 — demandes avec un devis accepté et sans avis, et l'état de leurs invitations.
     *
     * `$all` : y compris les demandes déjà relancées par la plateforme (relance manuelle de l'artisan).
     *
     * @return list<array{request_id: string, artisan_id: string, client_user_id: string, client_email: ?string, client_first_name: ?string, artisan_name: string, accepted_at: \DateTimeImmutable, service_at: ?\DateTimeImmutable, invited_at: ?\DateTimeImmutable, reminded_at: ?\DateTimeImmutable, artisan_reminded_at: ?\DateTimeImmutable}>
     */
    public function invitationCandidates(bool $all = false): array;

    /** @param 'invited_at'|'reminded_at'|'artisan_reminded_at' $column */
    public function markInvitation(string $requestId, string $column, \DateTimeImmutable $at): void;
}
