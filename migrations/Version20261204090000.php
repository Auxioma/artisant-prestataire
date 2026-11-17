<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T-705d1 — C07.4 « Télécharger mes données » : archive ZIP (JSON + PDF lisible) générée hors requête, chiffrée au repos,
 * téléchargeable 7 jours par son titulaire.
 */
final class Version20261204090000 extends AbstractMigration
{
    private const string PURPOSES = "'artisan_document', 'artisan_logo', 'request_photo', 'message_attachment', 'quote_pdf', 'review_photo', 'portfolio_photo', 'contact_attachment', 'client_avatar'";
    private const string MIMES = "'image/jpeg', 'image/png', 'image/webp', 'application/pdf'";

    public function getDescription(): string
    {
        return 'identity.data_export ; stored_file : usage data_export, type application/zip';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shared.stored_file DROP CONSTRAINT stored_file_purpose_check');
        $this->addSql('ALTER TABLE shared.stored_file ADD CONSTRAINT stored_file_purpose_check CHECK (purpose IN ('.self::PURPOSES.", 'data_export'))");
        $this->addSql('ALTER TABLE shared.stored_file DROP CONSTRAINT stored_file_mime_check');
        $this->addSql('ALTER TABLE shared.stored_file ADD CONSTRAINT stored_file_mime_check CHECK (mime IN ('.self::MIMES.", 'application/zip'))");
        $this->addSql("ALTER TABLE shared.stored_file ADD CONSTRAINT stored_file_export_encrypted CHECK (purpose <> 'data_export' OR status <> 'available' OR wrapped_dek IS NOT NULL)");
        $this->addSql(<<<'SQL'
            CREATE TABLE identity.data_export (
                id uuid PRIMARY KEY,
                user_id uuid NOT NULL REFERENCES identity.user_account (id) ON DELETE CASCADE,
                status varchar(10) NOT NULL CHECK (status IN ('requested', 'ready', 'failed', 'expired')),
                file_id uuid NULL REFERENCES shared.stored_file (id) ON DELETE SET NULL,
                requested_at timestamptz NOT NULL,
                ready_at timestamptz NULL,
                expires_at timestamptz NULL,
                CHECK (status <> 'ready' OR (file_id IS NOT NULL AND ready_at IS NOT NULL AND expires_at IS NOT NULL))
            )
            SQL);
        $this->addSql('CREATE INDEX data_export_user_idx ON identity.data_export (user_id, requested_at DESC)');
        $this->addSql("CREATE INDEX data_export_expiry_idx ON identity.data_export (expires_at) WHERE status = 'ready'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE identity.data_export');
        $this->addSql("DELETE FROM shared.stored_file WHERE purpose = 'data_export'");
        $this->addSql('ALTER TABLE shared.stored_file DROP CONSTRAINT stored_file_export_encrypted');
        $this->addSql('ALTER TABLE shared.stored_file DROP CONSTRAINT stored_file_mime_check');
        $this->addSql('ALTER TABLE shared.stored_file ADD CONSTRAINT stored_file_mime_check CHECK (mime IN ('.self::MIMES.'))');
        $this->addSql('ALTER TABLE shared.stored_file DROP CONSTRAINT stored_file_purpose_check');
        $this->addSql('ALTER TABLE shared.stored_file ADD CONSTRAINT stored_file_purpose_check CHECK (purpose IN ('.self::PURPOSES.'))');
    }
}
