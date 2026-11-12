<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** T-209 — comptes de l'équipe d'administration (BO14, ADR-0013) : 2FA TOTP obligatoire pour tout compte actif. */
final class Version20261113150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Back-office : admin.admin_user';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE admin.admin_user (
                id uuid PRIMARY KEY,
                email varchar(254) NOT NULL UNIQUE CHECK (email = lower(email)),
                name varchar(80) NOT NULL,
                role varchar(20) NOT NULL CHECK (role IN ('super_admin', 'moderator', 'support', 'content', 'finance', 'read_only')),
                status varchar(10) NOT NULL CHECK (status IN ('invited', 'active', 'disabled')),
                password_hash varchar(255) NULL,
                totp_secret varchar(255) NULL,
                backup_codes jsonb NOT NULL DEFAULT '[]',
                invitation_token_hash char(64) NULL,
                invitation_expires_at timestamptz NULL,
                invited_by uuid NULL REFERENCES admin.admin_user (id),
                activated_at timestamptz NULL,
                last_login_at timestamptz NULL,
                created_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL,
                version integer NOT NULL DEFAULT 1,
                -- ADR-0013 : aucun compte actif sans mot de passe ni double authentification.
                CHECK (status <> 'active' OR (password_hash IS NOT NULL AND totp_secret IS NOT NULL)),
                CHECK ((invitation_token_hash IS NULL) = (invitation_expires_at IS NULL))
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX admin_user_invitation_uniq ON admin.admin_user (invitation_token_hash) WHERE invitation_token_hash IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE admin.admin_user');
    }
}
