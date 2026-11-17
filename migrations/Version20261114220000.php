<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** T-307b — P16 : messages du formulaire de contact (accusé E-90, alerte au support) et pièces jointes chiffrées. */
final class Version20261114220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Contact : content.contact_message, usage de fichier contact_attachment';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shared.stored_file DROP CONSTRAINT stored_file_purpose_check');
        $this->addSql("ALTER TABLE shared.stored_file ADD CONSTRAINT stored_file_purpose_check CHECK (purpose IN ('artisan_document', 'artisan_logo', 'request_photo', 'message_attachment', 'quote_pdf', 'review_photo', 'portfolio_photo', 'contact_attachment'))");
        $this->addSql(<<<'SQL'
            CREATE TABLE content.contact_message (
                id uuid PRIMARY KEY,
                reference varchar(20) NOT NULL UNIQUE,
                reason varchar(80) NOT NULL,
                name varchar(100) NOT NULL,
                email varchar(254) NOT NULL,
                phone varchar(20) NULL,
                request_reference varchar(30) NULL,
                message text NOT NULL CHECK (char_length(message) BETWEEN 20 AND 5000),
                attachment_file_id uuid NULL REFERENCES shared.stored_file (id),
                user_id uuid NULL,
                ip_hash char(64) NULL,
                status varchar(20) NOT NULL DEFAULT 'new' CHECK (status IN ('new', 'answered', 'closed')),
                created_at timestamptz NOT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX contact_message_status_idx ON content.contact_message (status, created_at DESC)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE content.contact_message');
        $this->addSql("DELETE FROM shared.stored_file WHERE purpose = 'contact_attachment'");
        $this->addSql('ALTER TABLE shared.stored_file DROP CONSTRAINT stored_file_purpose_check');
        $this->addSql("ALTER TABLE shared.stored_file ADD CONSTRAINT stored_file_purpose_check CHECK (purpose IN ('artisan_document', 'artisan_logo', 'request_photo', 'message_attachment', 'quote_pdf', 'review_photo', 'portfolio_photo'))");
    }
}
