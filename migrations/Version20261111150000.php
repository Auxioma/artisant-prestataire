<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T-111 — preuve du consentement cookies (CDC §3.2, RGPD art. 7.1) : journal append-only.
 */
final class Version20261111150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Journal des consentements cookies (identity.consent)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE identity.consent (
                id uuid PRIMARY KEY,
                visitor_id uuid NOT NULL,
                user_id uuid NULL,
                choices jsonb NOT NULL,
                source text NOT NULL CHECK (source IN ('accept_all', 'refuse_all', 'custom')),
                policy_version integer NOT NULL CHECK (policy_version > 0),
                ip_hash char(64) NULL,
                given_at timestamptz NOT NULL,
                expires_at timestamptz NOT NULL,
                CHECK (expires_at > given_at)
            )
            SQL);
        $this->addSql('CREATE INDEX consent_visitor_idx ON identity.consent (visitor_id, given_at DESC)');
        $this->addSql('CREATE INDEX consent_user_idx ON identity.consent (user_id, given_at DESC) WHERE user_id IS NOT NULL');
        $this->addSql('CREATE INDEX consent_expires_idx ON identity.consent (expires_at)');
        // Ajout seul : modification interdite ; suppression permise uniquement pour une preuve expirée (purge RGPD, T-808).
        $this->addSql(<<<'SQL'
            CREATE FUNCTION identity.consent_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' AND OLD.expires_at < now() THEN
                    RETURN OLD;
                END IF;
                RAISE EXCEPTION 'Table identity.consent en ajout seul : % interdit', TG_OP USING ERRCODE = 'insufficient_privilege';
            END;
            $$
            SQL);
        $this->addSql('CREATE TRIGGER consent_append_only BEFORE UPDATE OR DELETE ON identity.consent FOR EACH ROW EXECUTE FUNCTION identity.consent_guard()');
        $this->addSql('CREATE TRIGGER consent_no_truncate BEFORE TRUNCATE ON identity.consent FOR EACH STATEMENT EXECUTE FUNCTION shared.forbid_mutation()');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE identity.consent');
        $this->addSql('DROP FUNCTION identity.consent_guard()');
    }
}
