<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T-701d — pièces jointes de la messagerie : une par message au plus, fichier du stockage partagé (quarantaine puis
 * analyse antivirus, ADR-0008) ; détruit avec la conversation à la purge `messages.retention_years`.
 */
final class Version20261128120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'messaging.attachment';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE messaging.attachment (
                file_id uuid PRIMARY KEY REFERENCES shared.stored_file (id),
                message_id uuid NOT NULL UNIQUE REFERENCES messaging.message (id) ON DELETE CASCADE,
                conversation_id uuid NOT NULL REFERENCES messaging.conversation (id) ON DELETE CASCADE,
                original_name varchar(120) NULL,
                created_at timestamptz NOT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX attachment_conversation_idx ON messaging.attachment (conversation_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE messaging.attachment');
    }
}
