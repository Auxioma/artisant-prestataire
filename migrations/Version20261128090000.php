<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T-701c — PR07 : note privée de chaque participant sur une conversation, réponses types enregistrées par l'artisan.
 */
final class Version20261128090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'messaging.participant.note, messaging.quick_reply';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE messaging.participant ADD note text NULL CHECK (char_length(note) <= 2000)');
        $this->addSql(<<<'SQL'
            CREATE TABLE messaging.quick_reply (
                id uuid PRIMARY KEY,
                user_id uuid NOT NULL,
                title varchar(60) NOT NULL CHECK (char_length(btrim(title)) > 0),
                body text NOT NULL CHECK (char_length(btrim(body)) > 0 AND char_length(body) <= 2000),
                created_at timestamptz NOT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX quick_reply_user_idx ON messaging.quick_reply (user_id, created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE messaging.quick_reply');
        $this->addSql('ALTER TABLE messaging.participant DROP COLUMN note');
    }
}
