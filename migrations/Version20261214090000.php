<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** T-903b — CDC §9 : mention « Accessibilité : non conforme » sur chaque page, liée à la déclaration (pied de page, administrable en BO10.5). */
final class Version20261214090000 extends AbstractMigration
{
    private const string LINK = 'route:content_accessibility';

    public function getDescription(): string
    {
        return 'Lien du pied de page vers la déclaration d’accessibilité';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "INSERT INTO content.menu_item (id, menu, label, link, icon, audience, position, updated_at)
             SELECT gen_random_uuid(), 'footer_info', :label, :link, NULL, 'all', COALESCE(MAX(position) + 1, 0), now()
             FROM content.menu_item WHERE menu = 'footer_info'",
            ['label' => 'Accessibilité : non conforme', 'link' => self::LINK],
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM content.menu_item WHERE link = :link', ['link' => self::LINK]);
    }
}
