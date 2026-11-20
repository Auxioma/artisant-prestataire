<?php

declare(strict_types=1);

namespace App\Administration\Application\Dashboard;

/** Unité d'un indicateur BO01.1 : fixe l'affichage de la valeur et de l'écart avec N-1. */
enum MetricUnit: string
{
    /** Nombre : écart relatif en %. */
    case Count = 'count';
    /** Proportion entre 0 et 1 : affichée en %, écart en points. */
    case Rate = 'rate';
    /** Durée en minutes : écart en minutes. */
    case Minutes = 'minutes';
    /** Montant en centimes : écart relatif en %. */
    case Cents = 'cents';
    /** Note sur 5 : écart en valeur. */
    case Rating = 'rating';
}
