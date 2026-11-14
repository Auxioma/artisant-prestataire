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

    /** Nom, adresse et n° de TVA intracommunautaire du client (ajouté une seule fois). */
    public function updateCustomerBilling(string $customerId, BillingAddress $address, string $idempotencyKey): void;

    /**
     * BO08.3 — crée le Coupon puis le Promotion Code.
     *
     * @return array{coupon: string, promotion: string}
     */
    public function createPromotion(PromotionSpec $spec): array;

    /** Désactive un code promo (le coupon reste pour les abonnements qui en bénéficient déjà). */
    public function deactivatePromotionCode(string $promotionCodeId, string $idempotencyKey): void;

    /** Code promo actif correspondant à la saisie, ou null s'il n'existe pas, a expiré ou est épuisé. */
    public function findPromotionCode(string $code): ?PromotionCode;

    public function createSubscriptionCheckout(CheckoutRequest $request): CheckoutSession;

    /** État courant d'une Subscription (statut, prix, période, résiliation programmée, métadonnées). */
    public function retrieveSubscription(string $subscriptionId): SubscriptionState;

    /**
     * Rapprochement (ADR-0010) : tous les abonnements du compte Stripe (y compris annulés récemment).
     *
     * @return iterable<SubscriptionState>
     */
    public function allSubscriptions(): iterable;

    /**
     * Rapprochement : factures créées depuis `$since`.
     *
     * @return iterable<InvoiceSnapshot>
     */
    public function invoicesSince(\DateTimeImmutable $since): iterable;

    /** Annulation immédiate (impayé au-delà de `billing.cancel_after_days`) ; sans effet si déjà annulée. */
    public function cancelSubscription(string $subscriptionId, string $idempotencyKey): void;

    /**
     * Changement de prix immédiat (montée de forfait, passage à l'annuel) avec le prorata `billing.proration_behavior` ;
     * un changement programmé auparavant est abandonné.
     */
    public function changeSubscriptionPrice(string $subscriptionId, string $priceId, string $prorationBehavior, string $idempotencyKey): void;

    /** Changement de prix à la prochaine échéance (descente de forfait, retour au mensuel), sans prorata. */
    public function scheduleSubscriptionPrice(string $subscriptionId, string $priceId, string $idempotencyKey): void;

    /** Moyen de paiement par défaut du client (factures), ou null s'il n'en a aucun. */
    public function defaultPaymentMethod(string $customerId): ?PaymentMethodSummary;

    /** PR13.2 — session du portail client Stripe (cartes, mandats SEPA) ; renvoie l'URL de redirection. */
    public function createPortalSession(string $customerId, string $returnUrl): string;

    /**
     * Copie du PDF d'une facture, téléchargée depuis le domaine de fichiers du prestataire uniquement (anti-SSRF).
     *
     * @throws ExternalServiceUnavailable
     */
    public function downloadInvoicePdf(string $url): string;

    /**
     * Remboursement total ou partiel d'une facture payée, sous forme d'avoir (Credit Note) remboursé sur le moyen de
     * paiement d'origine. Total : lignes de la facture reprises à l'identique ; partiel : une ligne du montant TTC.
     */
    public function refundInvoice(CreditNoteRequest $request): CreditNoteResult;

    /** Résiliation en fin de période payée (vrai) ou annulation de cette résiliation (faux). */
    public function setCancelAtPeriodEnd(string $subscriptionId, bool $cancel, string $idempotencyKey): void;
}
