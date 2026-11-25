<?php

declare(strict_types=1);

namespace App\Content\Domain\Home;

/** Les blocs de la page d'accueil P01 (BO10.1). En-tête et pied de page sont des composants globaux. */
enum HomeBlockType: string
{
    case Header = 'header';
    case Hero = 'hero';
    case Reassurance = 'reassurance';
    case QuickLinks = 'quick_links';
    case Categories = 'categories';
    case Pros = 'pros';
    case HowItWorks = 'how_it_works';
    case Cta = 'cta';
    case WhyUs = 'why_us';
    case Footer = 'footer';
    /** Maquette V2 : section « Les mieux notés » (après les catégories) ; ajoutée en dernier pour garder les codes P01.x. */
    case TopRated = 'top_rated';

    public function code(): string
    {
        if (self::TopRated === $this) {
            return 'P01.5b';
        }

        return 'P01.'.(array_search($this, self::cases(), true) + 1);
    }

    public function label(): string
    {
        return match ($this) {
            self::Header => 'Header',
            self::Hero => 'Hero (recherche)',
            self::Reassurance => 'Réassurance',
            self::QuickLinks => 'Accès rapides',
            self::Categories => 'Catégories',
            self::Pros => 'Professionnels près de chez vous',
            self::HowItWorks => 'Comment ça marche',
            self::Cta => 'Bannière CTA',
            self::WhyUs => 'Pourquoi TrouveMoi',
            self::Footer => 'Footer',
            self::TopRated => 'Les mieux notés',
        };
    }

    /** Composant global (BO > Apparence, Menus) : toujours affiché, en tête ou en pied, non éditable ici. */
    public function isGlobal(): bool
    {
        return self::Header === $this || self::Footer === $this;
    }
}
