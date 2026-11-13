<?php

declare(strict_types=1);

namespace App\Quote\Domain\Quote;

/**
 * RG-121 — cycle de statuts d'un devis : brouillon, envoyé, consulté, accepté, refusé (motif client), non retenu (autre
 * devis accepté), expiré (validité dépassée), retiré (RG-103, avant consultation), demande annulée.
 */
enum QuoteStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Viewed = 'viewed';
    case Accepted = 'accepted';
    case Refused = 'refused';
    case NotSelected = 'not_selected';
    case Expired = 'expired';
    case Withdrawn = 'withdrawn';
    case RequestCancelled = 'request_cancelled';

    private const array TRANSITIONS = [
        'draft' => ['sent'],
        'sent' => ['viewed', 'accepted', 'refused', 'not_selected', 'expired', 'withdrawn', 'request_cancelled'],
        'viewed' => ['accepted', 'refused', 'not_selected', 'expired', 'request_cancelled'],
        'accepted' => [],
        'refused' => [],
        'not_selected' => [],
        'expired' => [],
        'withdrawn' => [],
        'request_cancelled' => [],
    ];

    /** Devis occupant une place sur la demande (RG-070, RG-101 d). */
    public const array ACTIVE = [self::Sent, self::Viewed, self::Accepted];

    public function canTransitionTo(self $to): bool
    {
        return \in_array($to->value, self::TRANSITIONS[$this->value], true);
    }

    /** RG-102 — révisable tant qu'il n'est ni accepté ni refusé (ni clos). */
    public function isRevisable(): bool
    {
        return \in_array($this, [self::Sent, self::Viewed], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::Sent => 'Envoyé',
            self::Viewed => 'Consulté',
            self::Accepted => 'Accepté',
            self::Refused => 'Refusé',
            self::NotSelected => 'Non retenu',
            self::Expired => 'Expiré',
            self::Withdrawn => 'Retiré',
            self::RequestCancelled => 'Demande annulée',
        };
    }
}
