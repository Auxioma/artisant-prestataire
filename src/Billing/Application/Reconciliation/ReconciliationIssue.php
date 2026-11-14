<?php

declare(strict_types=1);

namespace App\Billing\Application\Reconciliation;

final readonly class ReconciliationIssue
{
    public const array LABELS = [
        'subscription_missing_local' => 'Abonnement Stripe inconnu de la plateforme',
        'subscription_missing_stripe' => 'Abonnement en vigueur introuvable chez Stripe',
        'subscription_status' => 'Statut d’abonnement différent',
        'subscription_period' => 'Fin de période différente',
        'invoice_missing' => 'Facture Stripe non répliquée',
        'invoice_status' => 'Statut de facture différent',
        'credits_not_granted' => 'Facture payée sans crédits de période',
        'ledger_balance' => 'Solde de crédits différent du registre',
        'webhook_backlog' => 'Événement Stripe non traité',
    ];

    public function __construct(
        public string $id,
        public string $kind,
        public string $reference,
        public string $details,
        public \DateTimeImmutable $detectedAt,
        public \DateTimeImmutable $lastSeenAt,
    ) {
    }

    public function label(): string
    {
        return self::LABELS[$this->kind] ?? $this->kind;
    }
}
