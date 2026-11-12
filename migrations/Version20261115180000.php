<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** T-309b — PR08.6 : portfolio (projets et photos avant / après), affiché sur la fiche P10 après analyse des fichiers. */
final class Version20261115180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Portfolio : artisan.portfolio_project, artisan.portfolio_photo';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE artisan.portfolio_project (
                id uuid PRIMARY KEY,
                artisan_id uuid NOT NULL REFERENCES artisan.artisan_profile (id) ON DELETE CASCADE,
                title varchar(100) NOT NULL,
                description varchar(1000) NULL,
                city varchar(100) NULL,
                done_on date NULL,
                job_id uuid NULL,
                position integer NOT NULL,
                created_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX portfolio_project_artisan_idx ON artisan.portfolio_project (artisan_id, position)');
        $this->addSql(<<<'SQL'
            CREATE TABLE artisan.portfolio_photo (
                id uuid PRIMARY KEY,
                project_id uuid NOT NULL REFERENCES artisan.portfolio_project (id) ON DELETE CASCADE,
                file_id uuid NOT NULL UNIQUE REFERENCES shared.stored_file (id),
                kind varchar(10) NOT NULL CHECK (kind IN ('photo', 'before', 'after')),
                position integer NOT NULL,
                created_at timestamptz NOT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX portfolio_photo_project_idx ON artisan.portfolio_photo (project_id, position)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE artisan.portfolio_photo');
        $this->addSql('DROP TABLE artisan.portfolio_project');
    }
}
