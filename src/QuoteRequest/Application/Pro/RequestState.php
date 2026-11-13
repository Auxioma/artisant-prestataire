<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Pro;

/** PR02.1 — statut d'une demande pour l'artisan (« répondue » arrive avec les devis, lot 5). */
enum RequestState: string
{
    case Unread = 'unread';
    case Viewed = 'viewed';
    case Ignored = 'ignored';
    /** RG-111 : complète, attribuée, expirée ou annulée après ouverture — lecture seule « Plus disponible ». */
    case Unavailable = 'unavailable';
    /** Q15 : artisan en attente de vérification, aperçu sans réponse possible. */
    case Preview = 'preview';

    public function label(): string
    {
        return match ($this) {
            self::Unread => 'Non lue',
            self::Viewed => 'Consultée',
            self::Ignored => 'Ignorée',
            self::Unavailable => 'Plus disponible',
            self::Preview => 'Aperçu',
        };
    }
}
