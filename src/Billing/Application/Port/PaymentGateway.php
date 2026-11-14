<?php

declare(strict_types=1);

namespace App\Billing\Application\Port;

use App\Billing\Domain\Plan\BillingInterval;
use App\Shared\Application\Integration\ExternalServiceUnavailable;

/**
 * Prestataire de paiement (cadrage §5, ADR-0009) — Stripe en production. Chaque écriture porte une clé d'idempotence
 * déterministe fournie par l'appelant : une nouvelle tentative (worker, double clic) ne crée jamais de doublon.
 * Montants en centimes, prix toujours HT (TVA calculée par Stripe Tax au paiement, T-603).
 *
 * Les écritures lèvent {@see ExternalServiceUnavailable} si la clé manque, si le prestataire est injoignable ou s'il
 * refuse la requête : l'appelant décide de réessayer.
 */
interface PaymentGateway
{
    /** Faux sans clé secrète (développement sans compte Stripe) : rien n'est alors envoyé. */
    public function isConfigured(): bool;

    /** « test », « live », ou null sans clé. */
    public function mode(): ?string;

    /**
     * @param array<string, string> $metadata
     *
     * @return string identifiant du client (cus_…)
     */
    public function createCustomer(string $email, string $name, array $metadata, string $idempotencyKey): string;

    /**
     * Crée le produit (identifiant null) ou le met à jour.
     *
     * @param array<string, string> $metadata
     *
     * @return string identifiant du produit (prod_…)
     */
    public function saveProduct(?string $productId, string $name, ?string $description, bool $active, array $metadata, string $idempotencyKey): string;

    /**
     * Prix récurrent HT en euros ; un prix n'est jamais modifié chez le prestataire, seulement retiré de la vente.
     *
     * @param array<string, string> $metadata
     *
     * @return string identifiant du prix (price_…)
     */
    public function createPrice(string $productId, int $amountCents, BillingInterval $interval, array $metadata, string $idempotencyKey): string;

    public function deactivatePrice(string $priceId, string $idempotencyKey): void;
}
