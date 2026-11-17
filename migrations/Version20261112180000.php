<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** T-205 — liens de réinitialisation du mot de passe (A05) : empreinte seulement, usage unique, 1 h. */
final class Version20261112180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Liens de réinitialisation du mot de passe (identity.password_reset_token)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE identity.password_reset_token (
                id uuid PRIMARY KEY,
                user_id uuid NOT NULL REFERENCES identity.user_account (id) DEFERRABLE INITIALLY DEFERRED,
                token_hash char(64) NOT NULL UNIQUE,
                requested_ip_hash char(64) NULL,
                created_at timestamptz NOT NULL,
                expires_at timestamptz NOT NULL,
                used_at timestamptz NULL,
                revoked_at timestamptz NULL,
                CHECK (expires_at > created_at),
                CHECK (used_at IS NULL OR revoked_at IS NULL)
            )
            SQL);
        // Un seul lien actif par compte (une nouvelle demande révoque la précédente).
        $this->addSql('CREATE UNIQUE INDEX password_reset_active_uniq ON identity.password_reset_token (user_id) WHERE used_at IS NULL AND revoked_at IS NULL');
        $this->addSql('CREATE INDEX password_reset_user_created_idx ON identity.password_reset_token (user_id, created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE identity.password_reset_token');
    }
}
