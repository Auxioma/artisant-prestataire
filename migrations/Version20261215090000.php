<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Corrections design V2 (maquette « Accueil — Desktop 1440 · V2 ») — pied de page : colonnes « Liens utiles »,
 * « Aide & compte », « Informations » reprises de la maquette ; inscription à la newsletter (« Restez informé »).
 */
final class Version20261215090000 extends AbstractMigration
{
    /** menu, libellé, lien, public — contenu de la maquette V2 */
    private const array FOOTER = [
        ['footer_useful', 'Accueil', 'route:public_home', 'all'],
        ['footer_useful', 'Catégories', 'route:catalog_categories', 'all'],
        ['footer_useful', 'Nos professionnels', 'route:search_directory', 'all'],
        ['footer_useful', 'Comment ça marche ?', 'route:content_how_it_works', 'all'],
        ['footer_useful', 'Tarifs / Offres', 'route:content_pricing', 'all'],
        ['footer_help', 'Aide / FAQ', 'route:content_faq', 'all'],
        ['footer_help', 'Blog', 'route:content_blog', 'all'],
        ['footer_help', 'Contact', 'route:content_contact', 'all'],
        ['footer_help', 'Créer un compte', 'route:identity_register_choice', 'visitor'],
        ['footer_help', 'Se connecter', 'route:identity_login', 'visitor'],
    ];
    /** Colonnes avant cette migration (Version20261209090000), pour le retour arrière. */
    private const array PREVIOUS = [
        ['footer_useful', 'Catégories', 'route:catalog_categories', 'all'],
        ['footer_useful', 'Nos professionnels', 'route:search_directory', 'all'],
        ['footer_useful', 'Les mieux notés', 'route:search_top_rated', 'all'],
        ['footer_useful', 'Disponibilités', 'route:search_availability', 'all'],
        ['footer_useful', 'Offres & promotions', 'route:search_offers', 'all'],
        ['footer_useful', 'Tarifs professionnels', 'route:content_pricing', 'all'],
        ['footer_useful', 'Blog', 'route:content_blog', 'all'],
        ['footer_help', 'Comment ça marche ?', 'route:content_how_it_works', 'all'],
        ['footer_help', 'Aide / FAQ', 'route:content_faq', 'all'],
        ['footer_help', 'Contact', 'route:content_contact', 'all'],
        ['footer_help', 'Créer mon compte pro', 'route:identity_register_pro', 'all'],
    ];

    public function getDescription(): string
    {
        return 'Pied de page V2 (colonnes de la maquette) et content.newsletter_subscriber';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE content.newsletter_subscriber (
                id uuid PRIMARY KEY,
                email varchar(254) NOT NULL CHECK (position('@' IN email) > 1),
                token varchar(64) NOT NULL UNIQUE,
                source varchar(30) NOT NULL,
                ip_hash varchar(64) NULL,
                subscribed_at timestamptz NOT NULL,
                unsubscribed_at timestamptz NULL
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX newsletter_subscriber_email_uniq ON content.newsletter_subscriber (lower(email))');

        $this->replace(self::FOOTER);
        $this->addSql("UPDATE content.menu_item SET label = 'Politique de confidentialité', updated_at = now() WHERE menu = 'footer_info' AND label = 'Confidentialité'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE content.newsletter_subscriber');
        $this->replace(self::PREVIOUS);
        $this->addSql("UPDATE content.menu_item SET label = 'Confidentialité', updated_at = now() WHERE menu = 'footer_info' AND label = 'Politique de confidentialité'");
    }

    /** @param list<array{string, string, string, string}> $items */
    private function replace(array $items): void
    {
        $this->addSql("DELETE FROM content.menu_item WHERE menu IN ('footer_useful', 'footer_help')");
        $positions = [];
        foreach ($items as [$menu, $label, $link, $audience]) {
            $positions[$menu] = ($positions[$menu] ?? -1) + 1;
            $this->addSql(
                'INSERT INTO content.menu_item (id, menu, label, link, icon, audience, position, updated_at) VALUES (gen_random_uuid(), :menu, :label, :link, NULL, :audience, :position, now())',
                ['menu' => $menu, 'label' => $label, 'link' => $link, 'audience' => $audience, 'position' => $positions[$menu]],
            );
        }
    }
}
