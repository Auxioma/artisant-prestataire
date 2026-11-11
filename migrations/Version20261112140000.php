<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** T-203 — jetons de validation d'e-mail (A06) : empreinte SHA-256 seulement, usage unique, expiration. */
final class Version20261112140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Jetons de validation d’e-mail (identity.email_verification_token)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE identity.email_verification_token (
                id uuid PRIMARY KEY,
                user_id uuid NOT NULL REFERENCES identity.user_account (id) DEFERRABLE INITIALLY DEFERRED,
                purpose varchar(20) NOT NULL CHECK (purpose IN ('signup', 'email_change')),
                new_email varchar(254) NULL,
                token_hash char(64) NOT NULL UNIQUE,
                created_at timestamptz NOT NULL,
                expires_at timestamptz NOT NULL,
                used_at timestamptz NULL,
                revoked_at timestamptz NULL,
                CHECK (expires_at > created_at),
                CHECK ((purpose = 'email_change') = (new_email IS NOT NULL)),
                CHECK (used_at IS NULL OR revoked_at IS NULL)
            )
            SQL);
        // Un seul lien actif par compte et par usage (le renvoi révoque le précédent).
        $this->addSql('CREATE UNIQUE INDEX email_token_active_uniq ON identity.email_verification_token (user_id, purpose) WHERE used_at IS NULL AND revoked_at IS NULL');
        $this->addSql('CREATE INDEX email_token_expires_idx ON identity.email_verification_token (expires_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE identity.email_verification_token');
    }
}
