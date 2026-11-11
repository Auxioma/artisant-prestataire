<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T-113 — référentiel géographique (BO07) : régions, départements, communes INSEE avec codes postaux et centroïdes
 * PostGIS (ADR-0004 : PostGIS = source de vérité géographique ; RG-022 : centroïde de commune, jamais d'adresse exacte).
 */
final class Version20261111190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Référentiel géographique INSEE (PostGIS)';
    }

    public function up(Schema $schema): void
    {
        // Extensions (rôle propriétaire requis ; sur une base managée, à activer par l'administrateur avant migration).
        $this->addSql('CREATE EXTENSION IF NOT EXISTS postgis');
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        $this->addSql('CREATE EXTENSION IF NOT EXISTS unaccent');
        // unaccent() n'est pas IMMUTABLE : enveloppe indexable (dictionnaire explicite).
        $this->addSql(<<<'SQL'
            CREATE FUNCTION catalog.immutable_unaccent(text) RETURNS text
            LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT
            AS $$ SELECT public.unaccent('public.unaccent'::regdictionary, $1) $$
            SQL);

        $this->addSql('CREATE TABLE catalog.region (code varchar(3) PRIMARY KEY, name text NOT NULL, slug varchar(100) NOT NULL UNIQUE)');
        $this->addSql(<<<'SQL'
            CREATE TABLE catalog.department (
                code varchar(3) PRIMARY KEY,
                name text NOT NULL,
                slug varchar(100) NOT NULL UNIQUE,
                region_code varchar(3) NOT NULL REFERENCES catalog.region (code)
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE catalog.commune (
                insee varchar(5) PRIMARY KEY CHECK (insee ~ '^([0-9]{2}|2A|2B)[0-9]{3}$'),
                name text NOT NULL,
                slug varchar(100) NOT NULL,
                postal_codes text[] NOT NULL DEFAULT '{}',
                department_code varchar(3) NOT NULL REFERENCES catalog.department (code),
                population integer NULL CHECK (population IS NULL OR population >= 0),
                centroid geography(Point, 4326) NOT NULL,
                active boolean NOT NULL DEFAULT true,
                seo_page_enabled boolean NOT NULL DEFAULT false,
                seo_text text NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now()
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX commune_slug_uniq ON catalog.commune (slug)');
        $this->addSql('CREATE INDEX commune_centroid_gist ON catalog.commune USING gist (centroid)');
        $this->addSql('CREATE INDEX commune_postal_codes_gin ON catalog.commune USING gin (postal_codes)');
        $this->addSql('CREATE INDEX commune_name_trgm ON catalog.commune USING gin (catalog.immutable_unaccent(lower(name)) gin_trgm_ops)');
        $this->addSql('CREATE INDEX commune_department_idx ON catalog.commune (department_code) WHERE active');
        $this->addSql('CREATE INDEX commune_seo_idx ON catalog.commune (population DESC) WHERE active AND seo_page_enabled');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE catalog.commune');
        $this->addSql('DROP TABLE catalog.department');
        $this->addSql('DROP TABLE catalog.region');
        $this->addSql('DROP FUNCTION catalog.immutable_unaccent(text)');
        // Les extensions sont conservées (partagées, et requises dès qu'une autre table les utilise).
    }
}
