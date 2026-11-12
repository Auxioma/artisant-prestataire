<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T-212 — tâches planifiées : masquage automatique d'un pro non assuré (RG-130), journal des alertes et relances
 * envoyées (E-23, E-25 : jamais deux fois la même).
 */
final class Version20261114090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Masquage RG-130 et artisan.reminder_sent';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE artisan.artisan_profile
                ADD hidden_at timestamptz NULL,
                ADD hidden_reason varchar(200) NULL,
                ADD CONSTRAINT artisan_profile_hidden_ck CHECK ((hidden_at IS NULL) = (hidden_reason IS NULL))
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE artisan.reminder_sent (
                kind varchar(30) NOT NULL CHECK (kind IN ('document_expiry', 'signup_incomplete')),
                subject_id uuid NOT NULL,
                step varchar(40) NOT NULL,
                sent_at timestamptz NOT NULL,
                PRIMARY KEY (kind, subject_id, step)
            )
            SQL);
        $this->addSql("CREATE INDEX document_expiry_watch_idx ON artisan.document (expires_on) WHERE status IN ('approved', 'pending_review')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX artisan.document_expiry_watch_idx');
        $this->addSql('DROP TABLE artisan.reminder_sent');
        $this->addSql('ALTER TABLE artisan.artisan_profile DROP CONSTRAINT artisan_profile_hidden_ck, DROP hidden_at, DROP hidden_reason');
    }
}
