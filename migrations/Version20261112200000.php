<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T-206 — socle Notification (CDC §6, annexe F, BO11) : modèles éditables et versionnés, journal d'envoi,
 * notifications in-app.
 */
final class Version20261112200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notification : modèles, versions, journal d’envoi, in-app';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SCHEMA IF NOT EXISTS notification');
        $this->addSql(<<<'SQL'
            CREATE TABLE notification.template (
                code varchar(10) PRIMARY KEY,
                label varchar(200) NOT NULL,
                recipient varchar(100) NOT NULL,
                channels text[] NOT NULL CHECK (channels <@ ARRAY['email', 'in_app', 'sms']::text[]),
                variables text[] NOT NULL DEFAULT '{}',
                subject text NULL,
                preheader text NULL,
                body_html text NULL,
                body_text text NULL,
                in_app_body text NULL,
                active boolean NOT NULL DEFAULT false,
                version integer NOT NULL DEFAULT 0,
                customized boolean NOT NULL DEFAULT false,
                updated_at timestamptz NOT NULL,
                updated_by uuid NULL,
                -- Un modèle e-mail actif a forcément un objet et ses deux corps.
                CHECK (NOT active OR NOT ('email' = ANY (channels)) OR (subject IS NOT NULL AND body_html IS NOT NULL AND body_text IS NOT NULL))
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE notification.template_version (
                code varchar(10) NOT NULL REFERENCES notification.template (code),
                version integer NOT NULL,
                subject text NULL,
                preheader text NULL,
                body_html text NULL,
                body_text text NULL,
                in_app_body text NULL,
                created_at timestamptz NOT NULL,
                created_by uuid NULL,
                PRIMARY KEY (code, version)
            )
            SQL);
        $this->addSql('CREATE TRIGGER template_version_append_only BEFORE UPDATE OR DELETE ON notification.template_version FOR EACH ROW EXECUTE FUNCTION shared.forbid_mutation()');

        // Journal d'envoi (BO11.2) : aucune adresse en clair — empreinte (recherche) + forme masquée (lecture).
        $this->addSql(<<<'SQL'
            CREATE TABLE notification.delivery (
                id uuid PRIMARY KEY,
                code varchar(10) NOT NULL,
                template_version integer NOT NULL,
                channel varchar(10) NOT NULL CHECK (channel IN ('email', 'in_app', 'sms')),
                user_id uuid NULL,
                recipient_hash char(64) NULL,
                recipient_masked varchar(80) NULL,
                status varchar(20) NOT NULL CHECK (status IN ('queued', 'sent', 'delivered', 'opened', 'bounced', 'complained', 'failed', 'skipped')),
                provider_message_id varchar(200) NULL,
                error varchar(500) NULL,
                created_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX delivery_created_idx ON notification.delivery (created_at DESC)');
        $this->addSql('CREATE INDEX delivery_user_idx ON notification.delivery (user_id, created_at DESC) WHERE user_id IS NOT NULL');
        $this->addSql('CREATE INDEX delivery_recipient_idx ON notification.delivery (recipient_hash) WHERE recipient_hash IS NOT NULL');
        $this->addSql('CREATE INDEX delivery_provider_idx ON notification.delivery (provider_message_id) WHERE provider_message_id IS NOT NULL');

        $this->addSql(<<<'SQL'
            CREATE TABLE notification.in_app (
                id uuid PRIMARY KEY,
                user_id uuid NOT NULL,
                code varchar(10) NOT NULL,
                body varchar(500) NOT NULL,
                link varchar(500) NULL CHECK (link IS NULL OR link LIKE '/%'),
                created_at timestamptz NOT NULL,
                read_at timestamptz NULL
            )
            SQL);
        $this->addSql('CREATE INDEX in_app_unread_idx ON notification.in_app (user_id, created_at DESC) WHERE read_at IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE notification.in_app');
        $this->addSql('DROP TABLE notification.delivery');
        $this->addSql('DROP TABLE notification.template_version');
        $this->addSql('DROP TABLE notification.template');
    }
}
