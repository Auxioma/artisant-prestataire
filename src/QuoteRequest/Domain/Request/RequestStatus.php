<?php

declare(strict_types=1);

namespace App\QuoteRequest\Domain\Request;

/**
 * RG-066 — cycle de statuts d'une demande (ADR-0005 : table de transitions déclarative, transitions portées par
 * l'agrégat QuoteRequest).
 *
 *   brouillon ─→ en modération ─approve→ ouverte ─(max atteint)→ complète
 *                en modération ─reject→ refusée
 *   ouverte | complète ─award→ attribuée ─complete→ terminée ; attribuée ─revokeAward→ ouverte | complète (RG-072)
 *   complète ─(retrait RG-103 | réouverture RG-070)→ ouverte
 *   ouverte | complète ─(échéance RG-064)→ expirée ─relaunch→ ouverte | complète
 *   brouillon | en modération | ouverte | complète | expirée ─cancel→ annulée
 */
enum RequestStatus: string
{
    case Draft = 'draft';
    case PendingModeration = 'pending_moderation';
    case Open = 'open';
    case Full = 'full';
    case Awarded = 'awarded';
    case Completed = 'completed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case Rejected = 'rejected';

    private const array TRANSITIONS = [
        'draft' => ['pending_moderation', 'open', 'cancelled'],
        'pending_moderation' => ['open', 'rejected', 'cancelled'],
        'open' => ['full', 'awarded', 'expired', 'cancelled'],
        'full' => ['open', 'awarded', 'expired', 'cancelled'],
        'awarded' => ['completed', 'open', 'full'],
        'expired' => ['open', 'full', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
        'rejected' => [],
    ];

    /** RG-065 — statuts comptés dans le plafond de demandes ouvertes simultanées d'un client (P-61). */
    public const array ACTIVE = [self::PendingModeration, self::Open, self::Full];

    /** RG-065 — statuts ignorés par l'anti-doublon : une demande annulée ou refusée peut être redéposée (P-61). */
    public const array NOT_DUPLICABLE = [self::Cancelled, self::Rejected];

    public function canTransitionTo(self $to): bool
    {
        return \in_array($to->value, self::TRANSITIONS[$this->value], true);
    }

    public function isFinal(): bool
    {
        return [] === self::TRANSITIONS[$this->value];
    }

    /** Libellés C02 (pastille de statut). */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::PendingModeration => 'En cours de vérification',
            self::Open => 'Ouverte',
            self::Full => 'Complète',
            self::Awarded => 'Attribuée',
            self::Completed => 'Terminée',
            self::Expired => 'Expirée',
            self::Cancelled => 'Annulée',
            self::Rejected => 'Refusée',
        };
    }
}
