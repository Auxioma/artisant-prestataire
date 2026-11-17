<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** T-705a — C05 favoris : artisans enregistrés par un client (date d'ajout). */
final class Version20261202090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'search.favorite';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE search.favorite (
                user_id uuid NOT NULL REFERENCES identity.user_account (id) ON DELETE CASCADE,
                artisan_id uuid NOT NULL REFERENCES artisan.artisan_profile (id) ON DELETE CASCADE,
                created_at timestamptz NOT NULL,
                PRIMARY KEY (user_id, artisan_id)
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE search.favorite');
    }
}
