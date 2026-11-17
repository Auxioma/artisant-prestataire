<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Domain\Document;

/** quarantined → pending_review (fichier sain) | rejected (antivirus) ; pending_review → approved | rejected (BO03, T-210) ; → expired (T-212) ; remplacé → shredded. */
enum DocumentStatus: string
{
    case Quarantined = 'quarantined';
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Shredded = 'shredded';

    /** Pièce déposée qui compte pour le dossier (en cours d'analyse, à contrôler ou acceptée). */
    public function counts(): bool
    {
        return \in_array($this, [self::Quarantined, self::PendingReview, self::Approved], true);
    }
}
