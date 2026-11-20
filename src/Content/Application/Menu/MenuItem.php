<?php

declare(strict_types=1);

namespace App\Content\Application\Menu;

/**
 * Élément de menu (BO10.5). `link` : `route:nom?param=valeur` (route du site), `/chemin`, `https://…`, ou
 * `special:categories` (méga-menu des catégories, BO06) / `special:cookies` (bouton « Gérer mes cookies »).
 */
final readonly class MenuItem
{
    public const array MENUS = [
        'main' => 'Menu principal',
        'footer_useful' => 'Pied de page — Liens utiles',
        'footer_info' => 'Pied de page — Informations',
        'footer_help' => 'Pied de page — Aide',
        'account_client' => 'Menu « Mon compte » client',
        'account_pro' => 'Menu « Mon compte » professionnel',
    ];
    public const array AUDIENCES = ['all' => 'Tout le monde', 'visitor' => 'Visiteurs non connectés', 'client' => 'Clients connectés', 'artisan' => 'Professionnels connectés'];
    public const array SPECIALS = ['special:categories' => 'Méga-menu des catégories', 'special:cookies' => 'Bouton « Gérer mes cookies »'];

    public function __construct(
        public string $id,
        public string $menu,
        public string $label,
        public string $link,
        public ?string $icon,
        public bool $newTab,
        public string $audience,
        public int $position,
        public bool $active,
    ) {
    }
}
