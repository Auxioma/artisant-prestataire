<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T-207b — inscription artisan, étapes 4 à 7 : zone d'intervention (RG-042, centroïde de commune RG-022), assurances
 * et labels (RG-032), justificatifs chiffrés (ADR-0008), description courte, logo, envoi du dossier (RG-043).
 */
final class Version20261113090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Inscription artisan : zone, assurances, justificatifs (artisan.document), profil express';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE artisan.artisan_profile
                ADD area_mode varchar(10) NULL CHECK (area_mode IN ('radius', 'list')),
                ADD area_center_insee varchar(5) NULL,
                ADD area_radius_km smallint NULL CHECK (area_radius_km > 0),
                ADD area_departments jsonb NOT NULL DEFAULT '[]',
                ADD area_communes jsonb NOT NULL DEFAULT '[]',
                ADD insurances jsonb NOT NULL DEFAULT '[]',
                ADD qualifications jsonb NOT NULL DEFAULT '[]',
                ADD short_description varchar(300) NULL,
                ADD logo_file_id uuid NULL,
                ADD submitted_at timestamptz NULL,
                ADD CONSTRAINT artisan_profile_area_ck CHECK (
                    area_mode IS NULL
                    OR (area_mode = 'radius' AND area_center_insee IS NOT NULL AND area_radius_km IS NOT NULL)
                    OR (area_mode = 'list' AND jsonb_array_length(area_departments) + jsonb_array_length(area_communes) > 0)
                ),
                ADD CONSTRAINT artisan_profile_step4_ck CHECK (completed_step < 4 OR area_mode IS NOT NULL),
                ADD CONSTRAINT artisan_profile_step7_ck CHECK (completed_step < 7 OR short_description IS NOT NULL),
                ADD CONSTRAINT artisan_profile_submitted_ck CHECK (status = 'incomplete' OR submitted_at IS NOT NULL)
            SQL);

        // Justificatifs : référence du fichier chiffré (shared.stored_file), jamais son contenu.
        $this->addSql(<<<'SQL'
            CREATE TABLE artisan.document (
                id uuid PRIMARY KEY,
                artisan_id uuid NOT NULL REFERENCES artisan.artisan_profile (id) DEFERRABLE INITIALLY DEFERRED,
                type varchar(40) NOT NULL CHECK (type ~ '^[a-z][a-z_]{1,39}$'),
                subject varchar(80) NULL,
                file_id uuid NOT NULL UNIQUE REFERENCES shared.stored_file (id) DEFERRABLE INITIALLY DEFERRED,
                original_name varchar(120) NULL,
                status varchar(20) NOT NULL CHECK (status IN ('quarantined', 'pending_review', 'approved', 'rejected', 'expired', 'shredded')),
                reason varchar(40) NULL,
                expires_on date NULL,
                uploaded_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX document_artisan_type_idx ON artisan.document (artisan_id, type)');
        // RG-032 / RG-130 : alertes d'expiration et masquage (T-212).
        $this->addSql("CREATE INDEX document_expiry_idx ON artisan.document (expires_on) WHERE status = 'approved'");

        // Logo / photo du profil (A03.7) : image publique après analyse, non chiffrée.
        $this->addSql('ALTER TABLE shared.stored_file DROP CONSTRAINT IF EXISTS stored_file_purpose_check');
        $this->addSql("ALTER TABLE shared.stored_file ADD CONSTRAINT stored_file_purpose_check CHECK (purpose IN ('artisan_document', 'artisan_logo', 'request_photo', 'message_attachment', 'quote_pdf', 'review_photo', 'portfolio_photo'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE artisan.document');
        $this->addSql('ALTER TABLE shared.stored_file DROP CONSTRAINT stored_file_purpose_check');
        $this->addSql("ALTER TABLE shared.stored_file ADD CONSTRAINT stored_file_purpose_check CHECK (purpose IN ('artisan_document', 'request_photo', 'message_attachment', 'quote_pdf', 'review_photo', 'portfolio_photo'))");
        $this->addSql(<<<'SQL'
            ALTER TABLE artisan.artisan_profile
                DROP area_mode, DROP area_center_insee, DROP area_radius_km, DROP area_departments, DROP area_communes,
                DROP insurances, DROP qualifications, DROP short_description, DROP logo_file_id, DROP submitted_at
            SQL);
    }
}
