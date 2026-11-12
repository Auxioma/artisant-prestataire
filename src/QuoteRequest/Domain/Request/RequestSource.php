<?php

declare(strict_types=1);

namespace App\QuoteRequest\Domain\Request;

/** BO04.1 « Source » : point d'entrée du tunnel (demande directe, recherche, pages SEO, accueil, autre). */
enum RequestSource: string
{
    case Direct = 'direct';
    case Search = 'search';
    case Seo = 'seo';
    case Home = 'home';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Direct => 'Directe',
            self::Search => 'Recherche',
            self::Seo => 'SEO',
            self::Home => 'Accueil',
            self::Other => 'Autre',
        };
    }
}
