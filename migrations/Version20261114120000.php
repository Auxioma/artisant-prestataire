<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T-301 — projection de recherche (ADR-0004) : une ligne par artisan public, position = centroïde de commune (RG-022).
 * Note, nombre d'avis, taux de réponse et disponibilité alimentés par leurs lots (Avis, Devis, Agenda).
 */
final class Version20261114120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recherche : search.artisan_search_view';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE search.artisan_search_view (
                artisan_id uuid PRIMARY KEY,
                user_id uuid NOT NULL,
                name varchar(120) NOT NULL,
                short_description varchar(300) NULL,
                logo_file_id uuid NULL,
                main_job_id uuid NULL,
                job_ids uuid[] NOT NULL DEFAULT '{}',
                commune_insee varchar(5) NULL,
                commune_name varchar(100) NULL,
                department_code varchar(3) NULL,
                location geography(Point, 4326) NULL,
                labels text[] NOT NULL DEFAULT '{}',
                completion smallint NOT NULL DEFAULT 0 CHECK (completion BETWEEN 0 AND 100),
                rating_avg numeric(3, 2) NULL CHECK (rating_avg IS NULL OR rating_avg BETWEEN 1 AND 5),
                reviews_count integer NOT NULL DEFAULT 0 CHECK (reviews_count >= 0),
                response_rate numeric(4, 3) NULL CHECK (response_rate IS NULL OR response_rate BETWEEN 0 AND 1),
                available_soon boolean NOT NULL DEFAULT false,
                verified_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX artisan_search_location_gist ON search.artisan_search_view USING gist (location)');
        $this->addSql('CREATE INDEX artisan_search_jobs_gin ON search.artisan_search_view USING gin (job_ids)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE search.artisan_search_view');
    }
}
