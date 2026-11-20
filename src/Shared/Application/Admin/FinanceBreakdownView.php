<?php

declare(strict_types=1);

namespace App\Shared\Application\Admin;

final readonly class FinanceBreakdownView
{
    /** Mois après l'abonnement auxquels la rétention d'une cohorte est mesurée (Figma : M1, M2, M3, M6). */
    public const array RETENTION_MONTHS = [1, 2, 3, 6];

    /**
     * @param list<array{label: string, mrr_cents: int}>                                                   $plans          MRR par forfait (annuels lissés à part)
     * @param list<array{month: string, size: int, retention: array<int, ?float>}>                         $cohorts        mois du premier abonnement, du plus ancien au plus récent
     * @param array{finished_at: \DateTimeImmutable, checked: int, issues: int, stripe_checked: bool}|null $reconciliation dernier rapprochement terminé
     */
    public function __construct(
        public array $plans,
        public array $cohorts,
        public int $unpaidArtisans,
        public int $promoRedemptions,
        public ?array $reconciliation,
        public int $openIssues,
        public ?float $webhooksProcessedRate,
    ) {
    }

    public function plansTotal(): int
    {
        return array_sum(array_column($this->plans, 'mrr_cents'));
    }
}
