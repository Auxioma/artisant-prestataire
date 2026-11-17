<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** T-407 — relances des demandes (E-19 avant échéance, E-19c brouillon) : jamais envoyées deux fois. */
final class Version20261118200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'request.reminder_sent';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE request.reminder_sent (
                kind varchar(30) NOT NULL CHECK (kind IN ('expiry_warning', 'draft_reminder')),
                subject_id uuid NOT NULL,
                step varchar(40) NOT NULL,
                sent_at timestamptz NOT NULL,
                PRIMARY KEY (kind, subject_id, step)
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE request.reminder_sent');
    }
}
