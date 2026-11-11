<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T-112 — référentiel Catalog (BO06) et redirections (BO12.2, slug modifié → 301).
 */
final class Version20261111170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Référentiel catégories / métiers et table des redirections';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE catalog.category (
                id uuid NOT NULL PRIMARY KEY,
                parent_id uuid DEFAULT NULL REFERENCES catalog.category (id),
                name varchar(80) NOT NULL,
                slug varchar(100) NOT NULL,
                icon varchar(32) NOT NULL,
                position integer NOT NULL CHECK (position >= 0),
                active boolean NOT NULL,
                featured boolean NOT NULL,
                description text DEFAULT NULL,
                created_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL,
                version integer DEFAULT 1 NOT NULL,
                CHECK (parent_id IS NULL OR parent_id <> id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX category_slug_uniq ON catalog.category (slug)');
        $this->addSql('CREATE INDEX category_parent_position_idx ON catalog.category (parent_id, position)');

        $this->addSql(<<<'SQL'
            CREATE TABLE catalog.job (
                id uuid NOT NULL PRIMARY KEY,
                category_id uuid NOT NULL REFERENCES catalog.category (id),
                name varchar(80) NOT NULL,
                slug varchar(100) NOT NULL,
                position integer NOT NULL CHECK (position >= 0),
                synonyms jsonb NOT NULL,
                required_documents jsonb NOT NULL,
                insurance_required boolean NOT NULL,
                decennial_required boolean NOT NULL,
                active boolean NOT NULL,
                replaced_by uuid DEFAULT NULL REFERENCES catalog.job (id),
                description text DEFAULT NULL,
                created_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL,
                version integer DEFAULT 1 NOT NULL,
                CHECK (replaced_by IS NULL OR replaced_by <> id),
                CHECK (jsonb_typeof(synonyms) = 'array' AND jsonb_typeof(required_documents) = 'array')
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX job_slug_uniq ON catalog.job (slug)');
        $this->addSql('CREATE INDEX job_category_position_idx ON catalog.job (category_id, position)');
        $this->addSql('CREATE INDEX job_synonyms_idx ON catalog.job USING gin (synonyms)');

        $this->addSql(<<<'SQL'
            CREATE TABLE content.redirect (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                source_path text NOT NULL,
                target_path text NOT NULL,
                status_code smallint NOT NULL DEFAULT 301 CHECK (status_code IN (301, 302)),
                match_prefix boolean NOT NULL DEFAULT false,
                origin text NOT NULL,
                hits integer NOT NULL DEFAULT 0,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CHECK (source_path LIKE '/%' AND target_path LIKE '/%' AND source_path <> target_path)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX redirect_source_uniq ON content.redirect (source_path)');
        $this->addSql('CREATE INDEX redirect_target_idx ON content.redirect (target_path)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE content.redirect');
        $this->addSql('DROP TABLE catalog.job');
        $this->addSql('DROP TABLE catalog.category');
    }
}
