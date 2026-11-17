<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** T-207a — profil professionnel (A03 étapes 1 à 3) : entreprise, SIRET unique (RG-041), métiers, statut RG-043. */
final class Version20261112220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Profil professionnel (artisan.artisan_profile)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE artisan.artisan_profile (
                id uuid PRIMARY KEY,
                user_id uuid NOT NULL UNIQUE,
                status varchar(30) NOT NULL CHECK (status IN ('incomplete', 'pending_verification', 'verified', 'rejected', 'suspended')),
                completed_step smallint NOT NULL CHECK (completed_step BETWEEN 1 AND 7),
                siret char(14) NULL CHECK (siret ~ '^[0-9]{14}$'),
                siren char(9) NULL CHECK (siren = left(siret, 9)),
                company_name varchar(120) NULL,
                trade_name varchar(120) NULL,
                legal_form varchar(10) NULL,
                ape varchar(10) NULL,
                vat_number varchar(20) NULL,
                vat_status varchar(20) NULL CHECK (vat_status IN ('assujetti', 'franchise')),
                hq_street varchar(200) NULL,
                hq_postal_code char(5) NULL,
                hq_city varchar(100) NULL,
                hq_city_insee varchar(5) NULL,
                company_created_on date NULL,
                siret_verified_at timestamptz NULL,
                jobs jsonb NOT NULL DEFAULT '[]',
                created_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL,
                version integer NOT NULL DEFAULT 1,
                CHECK (completed_step < 2 OR (siret IS NOT NULL AND company_name IS NOT NULL)),
                CHECK (completed_step < 3 OR jsonb_array_length(jobs) > 0),
                CHECK (status = 'incomplete' OR completed_step = 7)
            )
            SQL);
        // RG-041 : un SIRET = un compte professionnel (un profil refusé conserve son SIRET : nouvelle demande via le support).
        $this->addSql('CREATE UNIQUE INDEX artisan_profile_siret_uniq ON artisan.artisan_profile (siret) WHERE siret IS NOT NULL');
        $this->addSql('CREATE INDEX artisan_profile_status_idx ON artisan.artisan_profile (status, updated_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE artisan.artisan_profile');
    }
}
