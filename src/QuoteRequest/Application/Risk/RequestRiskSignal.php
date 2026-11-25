<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Risk;

/** RG-063 — signaux du score anti-spam d'une demande (codes stockés dans `risk_signals`). */
enum RequestRiskSignal: string
{
    case ForbiddenTerm = 'forbidden_term';
    case Link = 'link';
    case Contact = 'contact';
    case DuplicateText = 'duplicate_text';
    case Velocity = 'velocity';
    /** BO06 — métier proposé par le client : la demande attend la validation du référentiel (publiée ensuite). */
    case JobProposal = 'job_proposal';

    public function label(): string
    {
        return match ($this) {
            self::ForbiddenTerm => 'Terme interdit',
            self::Link => 'Lien dans la demande',
            self::Contact => 'Coordonnées dans la demande',
            self::DuplicateText => 'Texte identique à une autre demande',
            self::Velocity => 'Rafale de demandes (compte ou IP)',
            self::JobProposal => 'Métier proposé par le client (en attente du référentiel)',
        };
    }
}
