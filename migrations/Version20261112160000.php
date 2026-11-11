<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** T-204 — appareils de connexion connus (E-05 : alerte de connexion depuis un nouvel appareil). */
final class Version20261112160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Appareils de connexion (identity.session_device)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE identity.session_device (
                id uuid PRIMARY KEY,
                user_id uuid NOT NULL REFERENCES identity.user_account (id) DEFERRABLE INITIALLY DEFERRED,
                device_hash char(64) NOT NULL,
                description varchar(80) NOT NULL,
                ip_hash char(64) NULL,
                first_seen_at timestamptz NOT NULL,
                last_seen_at timestamptz NOT NULL,
                revoked_at timestamptz NULL,
                UNIQUE (user_id, device_hash),
                CHECK (last_seen_at >= first_seen_at)
            )
            SQL);
        $this->addSql('CREATE INDEX session_device_last_seen_idx ON identity.session_device (last_seen_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE identity.session_device');
    }
}
