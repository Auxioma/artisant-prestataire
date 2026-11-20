<?php

declare(strict_types=1);

namespace App\Administration\Application\Dashboard;

/**
 * BO01.1 — catalogue des indicateurs : les dix premiers sont les cartes de la maquette (Figma 186:75), les suivants
 * complètent la liste du CDC (« Tous les indicateurs »). La valeur vient d'une DashboardMetricSource de module.
 */
enum DashboardMetric: string
{
    case Visitors = 'visitors';
    case RequestsSubmitted = 'requests_submitted';
    case RequestsWithQuoteRate = 'requests_with_quote_rate';
    case FirstQuoteDelay = 'first_quote_delay_minutes';
    case AcceptanceRate = 'acceptance_rate';
    case VerifiedArtisans = 'verified_artisans';
    case Mrr = 'mrr_cents';
    case FreeToPaidRate = 'free_to_paid_rate';
    case ChurnRate = 'churn_rate';
    case ZeroCreditUnsubscribed = 'zero_credit_unsubscribed';
    case ClientSignups = 'client_signups';
    case ArtisanSignups = 'artisan_signups';
    case QuotesSent = 'quotes_sent';
    case ReviewsPublished = 'reviews_published';
    case ReviewAverage = 'review_average';
    case NewSubscribers = 'new_subscribers';
    case Arpu = 'arpu_cents';
    case Arr = 'arr_cents';
    case Collected = 'collected_cents';
    case Refunds = 'refunds_cents';
    case Unpaid = 'unpaid_cents';
    case Ltv = 'ltv_cents';

    /** BO15.3 — cartes de l'onglet Finance (Figma 186:6608), dans l'ordre ; seules ces cartes y figurent. */
    private const array FINANCE = [self::Mrr, self::Arr, self::Collected, self::Refunds, self::Unpaid, self::Ltv];
    /** Indicateurs propres à la finance : absents du tableau de bord. */
    private const array FINANCE_ONLY = [self::Arr, self::Collected, self::Refunds, self::Unpaid, self::Ltv];

    private const array FEATURED = [
        self::Visitors, self::RequestsSubmitted, self::RequestsWithQuoteRate, self::FirstQuoteDelay, self::AcceptanceRate,
        self::VerifiedArtisans, self::Mrr, self::FreeToPaidRate, self::ChurnRate, self::ZeroCreditUnsubscribed,
    ];

    public function label(): string
    {
        return match ($this) {
            self::Visitors => 'Visiteurs',
            self::RequestsSubmitted => 'Demandes déposées',
            self::RequestsWithQuoteRate => 'Demandes avec ≥ 1 devis',
            self::FirstQuoteDelay => 'Délai moyen 1er devis',
            self::AcceptanceRate => 'Taux d’acceptation',
            self::VerifiedArtisans => 'Artisans vérifiés actifs',
            self::Mrr => 'MRR',
            self::FreeToPaidRate => 'Conversion gratuit → payant',
            self::ChurnRate => 'Churn mensuel',
            self::ZeroCreditUnsubscribed => 'Artisans à 0 crédit non abonnés',
            self::ClientSignups => 'Inscriptions clients',
            self::ArtisanSignups => 'Inscriptions artisans',
            self::QuotesSent => 'Devis envoyés',
            self::ReviewsPublished => 'Avis publiés',
            self::ReviewAverage => 'Note moyenne des avis',
            self::NewSubscribers => 'Nouveaux abonnés',
            self::Arpu => 'ARPU (revenu moyen par abonné)',
            self::Arr => 'ARR',
            self::Collected => 'Encaissé (mois)',
            self::Refunds => 'Remboursements',
            self::Unpaid => 'Impayés',
            self::Ltv => 'LTV moyenne',
        };
    }

    public function unit(): MetricUnit
    {
        return match ($this) {
            self::RequestsWithQuoteRate, self::AcceptanceRate, self::FreeToPaidRate, self::ChurnRate => MetricUnit::Rate,
            self::FirstQuoteDelay => MetricUnit::Minutes,
            self::Mrr, self::Arpu, self::Arr, self::Collected, self::Refunds, self::Unpaid, self::Ltv => MetricUnit::Cents,
            self::ReviewAverage => MetricUnit::Rating,
            default => MetricUnit::Count,
        };
    }

    public function featured(): bool
    {
        return \in_array($this, self::FEATURED, true);
    }

    /** Carte de l'onglet Finance (BO15.3). */
    public function finance(): bool
    {
        return \in_array($this, self::FINANCE, true);
    }

    /** Affiché seulement dans l'onglet Finance. */
    public function financeOnly(): bool
    {
        return \in_array($this, self::FINANCE_ONLY, true);
    }

    /** Une baisse est une bonne nouvelle (délai, churn). */
    public function lowerIsBetter(): bool
    {
        return \in_array($this, [self::FirstQuoteDelay, self::ChurnRate, self::Refunds, self::Unpaid], true);
    }

    /** État actuel sans historique : pas de comparaison N-1, carte « À relancer ». */
    public function snapshotOnly(): bool
    {
        return self::ZeroCreditUnsubscribed === $this;
    }
}
