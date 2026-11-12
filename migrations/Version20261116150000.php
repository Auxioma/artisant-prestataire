<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** T-402a — P11 étapes 1 à 3 : questionnaires par métier (versionnés), brouillons de demande (RG-060) et leurs photos. */
final class Version20261116150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Questionnaires (catalog.questionnaire_form), brouillons (request.request_draft, request.draft_attachment)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE catalog.questionnaire_form (
                id uuid PRIMARY KEY,
                job_id uuid NOT NULL REFERENCES catalog.job (id),
                version integer NOT NULL CHECK (version >= 1),
                schema jsonb NOT NULL,
                active boolean NOT NULL,
                created_at timestamptz NOT NULL,
                CONSTRAINT questionnaire_form_version_uniq UNIQUE (job_id, version)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX questionnaire_form_active_uniq ON catalog.questionnaire_form (job_id) WHERE active');
        $this->addSql('ALTER TABLE request.quote_request ADD questionnaire_version integer NULL');

        $this->addSql(<<<'SQL'
            CREATE TABLE request.request_draft (
                id uuid PRIMARY KEY,
                owner_user_id uuid NULL REFERENCES identity.user_account (id),
                owner_token_hash varchar(64) NULL,
                step smallint NOT NULL CHECK (step BETWEEN 1 AND 6),
                payload jsonb NOT NULL,
                email_for_reminder varchar(180) NULL,
                reminder_consent boolean NOT NULL DEFAULT false,
                created_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL,
                expires_at timestamptz NOT NULL,
                CONSTRAINT request_draft_owner_ck CHECK ((owner_user_id IS NULL) <> (owner_token_hash IS NULL))
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX request_draft_user_uniq ON request.request_draft (owner_user_id) WHERE owner_user_id IS NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX request_draft_token_uniq ON request.request_draft (owner_token_hash) WHERE owner_token_hash IS NOT NULL');
        $this->addSql('CREATE INDEX request_draft_expiry_idx ON request.request_draft (expires_at)');
        $this->addSql(<<<'SQL'
            CREATE TABLE request.draft_attachment (
                draft_id uuid NOT NULL REFERENCES request.request_draft (id) ON DELETE CASCADE,
                file_id uuid NOT NULL UNIQUE REFERENCES shared.stored_file (id),
                original_name varchar(120) NOT NULL,
                position smallint NOT NULL,
                created_at timestamptz NOT NULL,
                PRIMARY KEY (draft_id, file_id)
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE request.draft_attachment');
        $this->addSql('DROP TABLE request.request_draft');
        $this->addSql('ALTER TABLE request.quote_request DROP questionnaire_version');
        $this->addSql('DROP TABLE catalog.questionnaire_form');
    }
}
