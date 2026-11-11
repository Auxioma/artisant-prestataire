<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T-201 — comptes clients et artisans (A01–A06). Table nommée `user_account` (« user » est un mot réservé SQL).
 */
final class Version20261112090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Comptes utilisateurs (identity.user_account)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE identity.user_account (
                id uuid PRIMARY KEY,
                email varchar(254) NOT NULL,
                email_canonical varchar(254) NOT NULL CHECK (email_canonical = lower(email_canonical)),
                password_hash varchar(255) NOT NULL,
                kind varchar(20) NOT NULL CHECK (kind IN ('client', 'artisan')),
                status varchar(30) NOT NULL CHECK (status IN ('pending_email', 'active', 'suspended', 'deletion_requested', 'anonymized')),
                first_name varchar(60) NOT NULL,
                last_name varchar(60) NOT NULL,
                email_verified_at timestamptz NULL,
                last_login_at timestamptz NULL,
                password_changed_at timestamptz NOT NULL,
                deletion_requested_at timestamptz NULL,
                anonymized_at timestamptz NULL,
                created_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL,
                version integer NOT NULL DEFAULT 1,
                CHECK (status <> 'active' OR email_verified_at IS NOT NULL),
                CHECK (status <> 'anonymized' OR anonymized_at IS NOT NULL),
                CHECK (status <> 'deletion_requested' OR deletion_requested_at IS NOT NULL),
                -- Jamais de mot de passe en clair : empreinte Argon2id, ou marqueur inutilisable après anonymisation.
                CHECK (password_hash LIKE '$argon2id$%' OR (status = 'anonymized' AND password_hash LIKE '!%'))
            )
            SQL);
        // Un e-mail = un compte vivant ; un compte anonymisé libère son adresse.
        $this->addSql("CREATE UNIQUE INDEX user_account_email_uniq ON identity.user_account (email_canonical) WHERE status <> 'anonymized'");
        $this->addSql('CREATE INDEX user_account_status_idx ON identity.user_account (status, created_at)');
        $this->addSql('CREATE INDEX user_account_last_login_idx ON identity.user_account (last_login_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE identity.user_account');
    }
}
