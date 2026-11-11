<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T-202 — téléphone du compte, profil client (A02), documents légaux versionnés et preuves d'acceptation (RG-002).
 */
final class Version20261112110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Téléphone, profil client, documents légaux et acceptations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE identity.user_account ADD phone varchar(16) NULL CHECK (phone ~ \'^\+[1-9][0-9]{7,14}$\'), ADD phone_verified_at timestamptz NULL');
        $this->addSql('ALTER TABLE identity.user_account ADD CHECK (phone_verified_at IS NULL OR phone IS NOT NULL)');

        $this->addSql(<<<'SQL'
            CREATE TABLE identity.client_profile (
                user_id uuid PRIMARY KEY REFERENCES identity.user_account (id) DEFERRABLE INITIALLY DEFERRED,
                type varchar(20) NOT NULL CHECK (type IN ('particulier', 'professionnel')),
                company_name varchar(120) NULL,
                siret char(14) NULL CHECK (siret ~ '^[0-9]{14}$'),
                siret_verified boolean NOT NULL DEFAULT false,
                postal_code char(5) NOT NULL CHECK (postal_code ~ '^[0-9]{5}$'),
                newsletter_opt_in_at timestamptz NULL,
                created_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL,
                CHECK (type = 'professionnel' OR (company_name IS NULL AND siret IS NULL)),
                CHECK (type = 'particulier' OR company_name IS NOT NULL),
                CHECK (siret IS NOT NULL OR NOT siret_verified)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE identity.legal_document (
                id uuid PRIMARY KEY,
                type varchar(20) NOT NULL CHECK (type IN ('cgu', 'cgv', 'cgv_pro', 'privacy')),
                version integer NOT NULL CHECK (version > 0),
                title varchar(200) NOT NULL,
                content_html text NOT NULL,
                content_hash char(64) NOT NULL,
                substantial boolean NOT NULL,
                published_at timestamptz NOT NULL,
                published_by uuid NULL,
                UNIQUE (type, version)
            )
            SQL);
        $this->addSql('CREATE INDEX legal_document_current_idx ON identity.legal_document (type, published_at DESC)');
        // Une version publiée ne change plus : la preuve d'acceptation renvoie à un texte figé (RG-002).
        $this->addSql('CREATE TRIGGER legal_document_immutable BEFORE UPDATE OR DELETE ON identity.legal_document FOR EACH ROW EXECUTE FUNCTION shared.forbid_mutation()');

        $this->addSql(<<<'SQL'
            CREATE TABLE identity.legal_acceptance (
                id uuid PRIMARY KEY,
                user_id uuid NOT NULL REFERENCES identity.user_account (id) DEFERRABLE INITIALLY DEFERRED, -- compte inséré au flush de l'ORM
                legal_document_id uuid NOT NULL REFERENCES identity.legal_document (id),
                content_hash char(64) NOT NULL,
                accepted_at timestamptz NOT NULL,
                ip_hash char(64) NULL,
                UNIQUE (user_id, legal_document_id)
            )
            SQL);
        $this->addSql('CREATE TRIGGER legal_acceptance_append_only BEFORE UPDATE OR DELETE ON identity.legal_acceptance FOR EACH ROW EXECUTE FUNCTION shared.forbid_mutation()');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE identity.legal_acceptance');
        $this->addSql('DROP TABLE identity.legal_document');
        $this->addSql('DROP TABLE identity.client_profile');
        $this->addSql('ALTER TABLE identity.user_account DROP phone_verified_at, DROP phone');
    }
}
