<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T-801d — BO10.5 menus : éléments du menu principal, des colonnes du pied de page et des menus « Mon compte »
 * (libellé, lien, cible, visibilité par profil, ordre). Amorcés avec les éléments affichés jusqu'ici.
 */
final class Version20261209090000 extends AbstractMigration
{
    /** menu, libellé, lien (`route:nom?param=valeur`, `/chemin`, `https://…`, `special:categories|cookies`), public */
    private const array SEED = [
        ['main', 'Accueil', 'route:public_home', 'all'],
        ['main', 'Catégories', 'special:categories', 'all'],
        ['main', 'Nos professionnels', 'route:search_directory', 'all'],
        ['main', 'Comment ça marche ?', 'route:content_how_it_works', 'all'],
        ['main', 'Aide / FAQ', 'route:content_faq', 'all'],
        ['footer_useful', 'Catégories', 'route:catalog_categories', 'all'],
        ['footer_useful', 'Nos professionnels', 'route:search_directory', 'all'],
        ['footer_useful', 'Les mieux notés', 'route:search_top_rated', 'all'],
        ['footer_useful', 'Disponibilités', 'route:search_availability', 'all'],
        ['footer_useful', 'Offres & promotions', 'route:search_offers', 'all'],
        ['footer_useful', 'Tarifs professionnels', 'route:content_pricing', 'all'],
        ['footer_useful', 'Blog', 'route:content_blog', 'all'],
        ['footer_info', 'CGU', 'route:identity_legal_document?slug=cgu', 'all'],
        ['footer_info', 'CGV', 'route:identity_legal_document?slug=cgv', 'all'],
        ['footer_info', 'Confidentialité', 'route:identity_legal_document?slug=confidentialite', 'all'],
        ['footer_info', 'Mentions légales', 'route:identity_legal_document?slug=mentions-legales', 'all'],
        ['footer_info', 'Cookies', 'route:identity_legal_document?slug=cookies', 'all'],
        ['footer_info', 'Gérer mes cookies', 'special:cookies', 'all'],
        ['footer_info', 'Plan du site', 'route:content_sitemap', 'all'],
        ['footer_help', 'Comment ça marche ?', 'route:content_how_it_works', 'all'],
        ['footer_help', 'Aide / FAQ', 'route:content_faq', 'all'],
        ['footer_help', 'Contact', 'route:content_contact', 'all'],
        ['footer_help', 'Créer mon compte pro', 'route:identity_register_pro', 'all'],
        ['account_pro', 'Tableau de bord', 'route:pro_dashboard', 'artisan'],
        ['account_pro', 'Demandes', 'route:pro_requests', 'artisan'],
        ['account_pro', 'Mes devis', 'route:pro_quotes', 'artisan'],
        ['account_pro', 'Abonnement', 'route:pro_subscription', 'artisan'],
        ['account_pro', 'Mon profil public', 'route:pro_profile', 'artisan'],
        ['account_client', 'Tableau de bord', 'route:client_dashboard', 'client'],
        ['account_client', 'Mes demandes', 'route:client_requests', 'client'],
        ['account_client', 'Favoris', 'route:client_favorites', 'client'],
        ['account_client', 'Profil', 'route:client_profile', 'client'],
    ];
    private const array ICONS = ['Tableau de bord' => 'grid', 'Demandes' => 'file', 'Mes demandes' => 'file', 'Mes devis' => 'briefcase', 'Abonnement' => 'star', 'Mon profil public' => 'user', 'Favoris' => 'heart', 'Profil' => 'user'];

    public function getDescription(): string
    {
        return 'content.menu_item (BO10.5), amorcé avec les menus existants';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE content.menu_item (
                id uuid PRIMARY KEY,
                menu varchar(20) NOT NULL CHECK (menu IN ('main', 'footer_useful', 'footer_info', 'footer_help', 'account_client', 'account_pro')),
                label varchar(60) NOT NULL CHECK (char_length(btrim(label)) > 0),
                link varchar(500) NOT NULL,
                icon varchar(32) NULL,
                new_tab boolean NOT NULL DEFAULT false,
                audience varchar(10) NOT NULL DEFAULT 'all' CHECK (audience IN ('all', 'visitor', 'client', 'artisan')),
                position integer NOT NULL,
                active boolean NOT NULL DEFAULT true,
                updated_at timestamptz NOT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX menu_item_menu_idx ON content.menu_item (menu, position)');
        $positions = [];
        foreach (self::SEED as [$menu, $label, $link, $audience]) {
            $positions[$menu] = ($positions[$menu] ?? -1) + 1;
            $this->addSql(
                'INSERT INTO content.menu_item (id, menu, label, link, icon, audience, position, updated_at) VALUES (gen_random_uuid(), :menu, :label, :link, :icon, :audience, :position, now())',
                ['menu' => $menu, 'label' => $label, 'link' => $link, 'icon' => str_starts_with($menu, 'account_') ? (self::ICONS[$label] ?? null) : null, 'audience' => $audience, 'position' => $positions[$menu]],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE content.menu_item');
    }
}
