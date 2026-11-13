<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Dispatch;

/** Raison de la présence d'un artisan parmi les destinataires d'une demande (RG-062). */
enum DispatchReason: string
{
    /** Artisan choisi par le client (demande directe, E-31). */
    case Direct = 'direct';
    /** Métier et commune couverts par la zone de l'artisan (E-30). */
    case Eligible = 'eligible';
    /** Diffusion étendue par l'équipe au-delà des zones déclarées (BO04, T-404b). */
    case Extended = 'extended';
}
