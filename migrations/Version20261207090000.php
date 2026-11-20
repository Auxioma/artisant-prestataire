<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T-801a — BO10.7 médiathèque : image d'origine (analysée), texte alternatif obligatoire, point focal (recadrage),
 * variantes WebP et AVIF générées automatiquement.
 */
final class Version20261207090000 extends AbstractMigration
{
    private const string PURPOSES = "'artisan_document', 'artisan_logo', 'request_photo', 'message_attachment', 'quote_pdf', 'review_photo', 'portfolio_photo', 'contact_attachment', 'client_avatar', 'data_export'";
    private const string MIMES = "'image/jpeg', 'image/png', 'image/webp', 'application/pdf', 'application/zip'";

    public function getDescription(): string
    {
        return 'content.media ; stored_file : usage media, type image/avif';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shared.stored_file DROP CONSTRAINT stored_file_purpose_check');
        $this->addSql('ALTER TABLE shared.stored_file ADD CONSTRAINT stored_file_purpose_check CHECK (purpose IN ('.self::PURPOSES.", 'media'))");
        $this->addSql('ALTER TABLE shared.stored_file DROP CONSTRAINT stored_file_mime_check');
        $this->addSql('ALTER TABLE shared.stored_file ADD CONSTRAINT stored_file_mime_check CHECK (mime IN ('.self::MIMES.", 'image/avif'))");
        $this->addSql(<<<'SQL'
            CREATE TABLE content.media (
                id uuid PRIMARY KEY,
                file_id uuid NOT NULL REFERENCES shared.stored_file (id),
                title varchar(120) NOT NULL,
                alt varchar(250) NOT NULL CHECK (char_length(btrim(alt)) > 0),
                focal varchar(2) NOT NULL DEFAULT 'cc' CHECK (focal IN ('tl', 'tc', 'tr', 'cl', 'cc', 'cr', 'bl', 'bc', 'br')),
                width integer NULL CHECK (width > 0),
                height integer NULL CHECK (height > 0),
                variants jsonb NOT NULL DEFAULT '{}'::jsonb,
                status varchar(10) NOT NULL DEFAULT 'processing' CHECK (status IN ('processing', 'ready', 'failed')),
                created_by uuid NULL,
                created_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX media_file_uniq ON content.media (file_id)');
        $this->addSql('CREATE INDEX media_created_idx ON content.media (created_at DESC)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE content.media');
        $this->addSql("DELETE FROM shared.stored_file WHERE purpose = 'media'");
        $this->addSql('ALTER TABLE shared.stored_file DROP CONSTRAINT stored_file_mime_check');
        $this->addSql('ALTER TABLE shared.stored_file ADD CONSTRAINT stored_file_mime_check CHECK (mime IN ('.self::MIMES.'))');
        $this->addSql('ALTER TABLE shared.stored_file DROP CONSTRAINT stored_file_purpose_check');
        $this->addSql('ALTER TABLE shared.stored_file ADD CONSTRAINT stored_file_purpose_check CHECK (purpose IN ('.self::PURPOSES.'))');
    }
}
