<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BO06 — image d'illustration d'une catégorie choisie dans la médiathèque du CMS (cartes de l'accueil « Explorez nos
 * catégories », annuaire). Une image supprimée de la médiathèque est simplement retirée de la catégorie.
 */
final class Version20261219090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'catalog.category.image_media_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE catalog.category ADD image_media_id uuid DEFAULT NULL REFERENCES content.media (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE catalog.category DROP image_media_id');
    }
}
