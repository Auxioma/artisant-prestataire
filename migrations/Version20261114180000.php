<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** T-306 — BO10.1 : versions de la page d'accueil (brouillon unique, publications et programmations, historique). */
final class Version20261114180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CMS : content.home_version';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE content.home_version (
                id uuid PRIMARY KEY,
                number integer NOT NULL UNIQUE CHECK (number > 0),
                blocks jsonb NOT NULL CHECK (jsonb_typeof(blocks) = 'array'),
                author_id uuid NOT NULL,
                created_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL,
                publish_at timestamptz NULL,
                published_by uuid NULL,
                CHECK ((publish_at IS NULL) = (published_by IS NULL))
            )
            SQL);
        // Au plus un brouillon à la fois.
        $this->addSql('CREATE UNIQUE INDEX home_version_single_draft ON content.home_version ((true)) WHERE publish_at IS NULL');
        $this->addSql('CREATE INDEX home_version_publish_idx ON content.home_version (publish_at DESC) WHERE publish_at IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE content.home_version');
    }
}
