<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** T-303 — fiche publique : option « afficher mon téléphone » (RG-030) et suivi des affichages au clic (statistiques). */
final class Version20261114150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fiche artisan : phone_public et artisan.phone_reveal';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE artisan.artisan_profile ADD phone_public boolean NOT NULL DEFAULT false');
        // Aucune donnée personnelle du visiteur : empreinte d'IP hachée (HMAC) pour dédoublonner les statistiques.
        $this->addSql(<<<'SQL'
            CREATE TABLE artisan.phone_reveal (
                id uuid PRIMARY KEY,
                artisan_id uuid NOT NULL REFERENCES artisan.artisan_profile (id) ON DELETE CASCADE,
                visitor_hash char(64) NULL,
                revealed_at timestamptz NOT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX phone_reveal_artisan_idx ON artisan.phone_reveal (artisan_id, revealed_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE artisan.phone_reveal');
        $this->addSql('ALTER TABLE artisan.artisan_profile DROP phone_public');
    }
}
