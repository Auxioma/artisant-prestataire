<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** T-308b — BO12 : gabarits title / description par type de page, surcharges page par page, rapport des 404. */
final class Version20261115120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SEO : content.seo_template, content.seo_override, content.not_found';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE content.seo_template (
                page_type varchar(30) PRIMARY KEY,
                title varchar(200) NULL,
                description varchar(320) NULL,
                updated_at timestamptz NOT NULL,
                updated_by uuid NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE content.seo_override (
                path varchar(500) PRIMARY KEY CHECK (path LIKE '/%' AND path NOT LIKE '//%'),
                title varchar(200) NULL,
                description varchar(320) NULL,
                noindex boolean NOT NULL DEFAULT false,
                updated_at timestamptz NOT NULL,
                updated_by uuid NOT NULL
            )
            SQL);
        // Rapport des 404 (BO12.2) : chemin, nombre d'occurrences, dernière page d'origine ; aucune donnée personnelle.
        $this->addSql(<<<'SQL'
            CREATE TABLE content.not_found (
                path varchar(500) PRIMARY KEY,
                hits integer NOT NULL DEFAULT 1 CHECK (hits > 0),
                last_referer varchar(500) NULL,
                first_seen_at timestamptz NOT NULL,
                last_seen_at timestamptz NOT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX not_found_hits_idx ON content.not_found (hits DESC, last_seen_at DESC)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE content.not_found');
        $this->addSql('DROP TABLE content.seo_override');
        $this->addSql('DROP TABLE content.seo_template');
    }
}
