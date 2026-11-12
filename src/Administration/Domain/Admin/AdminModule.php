<?php

declare(strict_types=1);

namespace App\Administration\Domain\Admin;

/** Modules du back-office (BO01 à BO16), regroupés comme la navigation Figma (186:76). */
enum AdminModule: string
{
    case Dashboard = 'dashboard';
    case Clients = 'clients';
    case Artisans = 'artisans';
    case Requests = 'requests';
    case Moderation = 'moderation';
    case Support = 'support';
    case Catalog = 'catalog';
    case Geo = 'geo';
    case Plans = 'plans';
    case Offers = 'offers';
    case Cms = 'cms';
    case Notifications = 'notifications';
    case Seo = 'seo';
    case Settings = 'settings';
    case Team = 'team';
    case Logs = 'logs';

    public function label(): string
    {
        return match ($this) {
            self::Dashboard => 'Tableau de bord',
            self::Clients => 'Clients',
            self::Artisans => 'Artisans & vérification',
            self::Requests => 'Demandes & devis',
            self::Moderation => 'Modération',
            self::Support => 'Support',
            self::Catalog => 'Référentiel métiers',
            self::Geo => 'Zones géographiques',
            self::Plans => 'Forfaits & crédits',
            self::Offers => 'Offres & promotions',
            self::Cms => 'CMS',
            self::Notifications => 'Emails & notifications',
            self::Seo => 'SEO',
            self::Settings => 'Paramètres',
            self::Team => 'Équipe & rôles',
            self::Logs => 'Journaux & finance',
        };
    }

    /** Rubrique de la navigation. */
    public function group(): string
    {
        return match ($this) {
            self::Dashboard => 'Pilotage',
            self::Clients, self::Artisans => 'Utilisateurs',
            self::Requests, self::Moderation, self::Support => 'Activité',
            self::Catalog, self::Geo => 'Catalogue',
            self::Plans, self::Offers => 'Monétisation',
            self::Cms, self::Notifications, self::Seo => 'Contenus',
            self::Settings, self::Team, self::Logs => 'Système',
        };
    }

    public function permission(AdminAction $action): string
    {
        return 'admin.'.$this->value.'.'.$action->value;
    }
}
