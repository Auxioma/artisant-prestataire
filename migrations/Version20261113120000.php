<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T-208 — statuts du compte pro (RG-043 : motif, horodatage, auteur), complétude (PR08.1, RG-011) et projections
 * de diffusion / recherche : métiers exercés (`artisan_job`) et communes couvertes (`service_area_commune`, RG-021, RG-062).
 */
final class Version20261113120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Compte pro : statuts RG-043, complétude, artisan_job et service_area_commune';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE artisan.artisan_profile
                ADD verified_at timestamptz NULL,
                ADD status_reason varchar(40) NULL,
                ADD status_comment varchar(500) NULL,
                ADD status_changed_at timestamptz NULL,
                ADD status_changed_by uuid NULL,
                ADD completion_rate smallint NOT NULL DEFAULT 0 CHECK (completion_rate BETWEEN 0 AND 100),
                ADD CONSTRAINT artisan_profile_verified_ck CHECK (status <> 'verified' OR verified_at IS NOT NULL),
                ADD CONSTRAINT artisan_profile_rejected_ck CHECK (status <> 'rejected' OR status_reason IS NOT NULL)
            SQL);
        // RG-011 : candidats aux mises en avant (vérifiés, complétude élevée).
        $this->addSql("CREATE INDEX artisan_profile_featured_idx ON artisan.artisan_profile (completion_rate DESC) WHERE status = 'verified'");

        // Métiers exercés : un seul principal ; sans clé étrangère vers catalog (frontière de module), indexé pour la diffusion.
        $this->addSql(<<<'SQL'
            CREATE TABLE artisan.artisan_job (
                artisan_id uuid NOT NULL REFERENCES artisan.artisan_profile (id) ON DELETE CASCADE DEFERRABLE INITIALLY DEFERRED,
                job_id uuid NOT NULL,
                is_main boolean NOT NULL DEFAULT false,
                PRIMARY KEY (artisan_id, job_id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX artisan_job_main_uniq ON artisan.artisan_job (artisan_id) WHERE is_main');
        $this->addSql('CREATE INDEX artisan_job_job_idx ON artisan.artisan_job (job_id)');

        // Communes couvertes par la zone d'intervention (RG-042 : effet immédiat ; RG-062 : diffusion ; RG-021 : recherche).
        $this->addSql(<<<'SQL'
            CREATE TABLE artisan.service_area_commune (
                artisan_id uuid NOT NULL REFERENCES artisan.artisan_profile (id) ON DELETE CASCADE DEFERRABLE INITIALLY DEFERRED,
                insee varchar(5) NOT NULL,
                PRIMARY KEY (artisan_id, insee)
            )
            SQL);
        $this->addSql('CREATE INDEX service_area_commune_insee_idx ON artisan.service_area_commune (insee)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE artisan.service_area_commune');
        $this->addSql('DROP TABLE artisan.artisan_job');
        $this->addSql(<<<'SQL'
            ALTER TABLE artisan.artisan_profile
                DROP verified_at, DROP status_reason, DROP status_comment, DROP status_changed_at, DROP status_changed_by, DROP completion_rate
            SQL);
    }
}
