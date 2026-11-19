<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** T-806c2 — BO15.2 exports planifiés : fichiers CSV chiffrés, conservés un temps limité, téléchargement journalisé. */
final class Version20261212090000 extends AbstractMigration
{
    private const string PURPOSES = "'artisan_document', 'artisan_logo', 'request_photo', 'message_attachment', 'quote_pdf', 'review_photo', 'portfolio_photo', 'contact_attachment', 'client_avatar', 'data_export', 'media'";
    private const string MIMES = "'image/jpeg', 'image/png', 'image/webp', 'application/pdf', 'application/zip', 'image/avif'";

    public function getDescription(): string
    {
        return 'Exports planifiés du back-office (BO15.2)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shared.stored_file DROP CONSTRAINT stored_file_purpose_check');
        $this->addSql('ALTER TABLE shared.stored_file ADD CONSTRAINT stored_file_purpose_check CHECK (purpose IN ('.self::PURPOSES.", 'scheduled_export'))");
        $this->addSql('ALTER TABLE shared.stored_file DROP CONSTRAINT stored_file_mime_check');
        $this->addSql('ALTER TABLE shared.stored_file ADD CONSTRAINT stored_file_mime_check CHECK (mime IN ('.self::MIMES.", 'text/csv'))");
        $this->addSql("ALTER TABLE shared.stored_file ADD CONSTRAINT stored_file_scheduled_export_encrypted CHECK (purpose <> 'scheduled_export' OR status <> 'available' OR wrapped_dek IS NOT NULL)");
        $this->addSql(<<<'SQL'
            CREATE TABLE admin.scheduled_export (
                id uuid PRIMARY KEY,
                kind varchar(40) NOT NULL CHECK (kind ~ '^[a-z_]+$'),
                period_label varchar(60) NOT NULL,
                file_id uuid NOT NULL REFERENCES shared.stored_file (id),
                row_count integer NOT NULL CHECK (row_count >= 0),
                created_at timestamptz NOT NULL,
                expires_at timestamptz NOT NULL CHECK (expires_at > created_at)
            )
            SQL);
        $this->addSql('CREATE INDEX scheduled_export_kind_idx ON admin.scheduled_export (kind, created_at DESC)');
        $this->addSql('CREATE INDEX scheduled_export_expiry_idx ON admin.scheduled_export (expires_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE admin.scheduled_export');
        $this->addSql("DELETE FROM shared.stored_file WHERE purpose = 'scheduled_export'");
        $this->addSql('ALTER TABLE shared.stored_file DROP CONSTRAINT stored_file_scheduled_export_encrypted');
        $this->addSql('ALTER TABLE shared.stored_file DROP CONSTRAINT stored_file_mime_check');
        $this->addSql('ALTER TABLE shared.stored_file ADD CONSTRAINT stored_file_mime_check CHECK (mime IN ('.self::MIMES.'))');
        $this->addSql('ALTER TABLE shared.stored_file DROP CONSTRAINT stored_file_purpose_check');
        $this->addSql('ALTER TABLE shared.stored_file ADD CONSTRAINT stored_file_purpose_check CHECK (purpose IN ('.self::PURPOSES.'))');
    }
}
