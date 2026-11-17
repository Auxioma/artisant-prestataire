<?php

declare(strict_types=1);

namespace App\QuoteRequest\Domain\Request;

/** BO04.1 — motifs normalisés de refus d'une demande en modération (repris dans E-10b). */
enum RejectionReason: string
{
    case Spam = 'spam';
    case ContactDetails = 'contact_details';
    case OffTopic = 'off_topic';
    case Offensive = 'offensive';
    case Duplicate = 'duplicate';
    case Incomplete = 'incomplete';

    public function label(): string
    {
        return match ($this) {
            self::Spam => 'Contenu publicitaire ou frauduleux',
            self::ContactDetails => 'Coordonnées dans la demande',
            self::OffTopic => 'Hors sujet (pas une prestation proposée sur TrouveMoi)',
            self::Offensive => 'Propos injurieux',
            self::Duplicate => 'Demande en double',
            self::Incomplete => 'Demande trop imprécise',
        };
    }
}
