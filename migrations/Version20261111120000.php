<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot 1 — socle : schémas par module (ADR-0003), paramètres historisés (ADR-0007, RG-014),
 * journal d'audit append-only chaîné (BO15), outbox transactionnelle (ADR-0011).
 */
final class Version20261111120000 extends AbstractMigration
{
    private const array SCHEMAS = [
        'shared', 'identity', 'catalog', 'artisan', 'search', 'request', 'quote', 'billing',
        'messaging', 'review', 'moderation', 'content', 'notification', 'admin',
    ];

    public function getDescription(): string
    {
        return 'Socle : schémas des modules, paramètres administrables, journal d\'audit, outbox';
    }

    public function up(Schema $schema): void
    {
        foreach (self::SCHEMAS as $name) {
            $this->addSql(\sprintf('CREATE SCHEMA IF NOT EXISTS %s', $name));
        }

        // Garde générique « ajout seul » réutilisée par le ledger de crédits (RG-107), l'audit, les historiques.
        $this->addSql(<<<'SQL'
            CREATE FUNCTION shared.forbid_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Table % en ajout seul : % interdit', TG_TABLE_SCHEMA || '.' || TG_TABLE_NAME, TG_OP
                    USING ERRCODE = 'insufficient_privilege';
            END;
            $$
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE shared.outbox_message (
                id uuid PRIMARY KEY,
                message_type text NOT NULL,
                body text NOT NULL,
                headers jsonb NOT NULL DEFAULT '{}'::jsonb,
                occurred_at timestamptz NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                dispatched_at timestamptz NULL
            )
            SQL);
        $this->addSql('CREATE INDEX outbox_pending_idx ON shared.outbox_message (occurred_at, id) WHERE dispatched_at IS NULL');

        $this->addSql(<<<'SQL'
            CREATE TABLE admin.setting (
                key text PRIMARY KEY,
                value jsonb NOT NULL,
                type text NOT NULL,
                updated_by uuid NULL,
                updated_at timestamptz NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE admin.setting_history (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                key text NOT NULL REFERENCES admin.setting (key),
                value_before jsonb NULL,
                value_after jsonb NOT NULL,
                changed_by uuid NULL,
                reason text NOT NULL CHECK (length(btrim(reason)) > 0),
                changed_at timestamptz NOT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX setting_history_key_idx ON admin.setting_history (key, changed_at DESC)');
        $this->addSql('CREATE TRIGGER setting_history_append_only BEFORE UPDATE OR DELETE ON admin.setting_history FOR EACH ROW EXECUTE FUNCTION shared.forbid_mutation()');

        $this->addSql(<<<'SQL'
            CREATE TABLE admin.audit_log (
                seq bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                id uuid NOT NULL UNIQUE,
                occurred_at timestamptz NOT NULL,
                actor_type text NOT NULL CHECK (actor_type IN ('user', 'admin', 'system')),
                actor_id uuid NULL,
                impersonator_id uuid NULL,
                action text NOT NULL,
                subject_type text NULL,
                subject_id text NULL,
                ip inet NULL,
                context jsonb NOT NULL DEFAULT '{}'::jsonb,
                canonical text NOT NULL,
                prev_hash char(64) NULL,
                hash char(64) NOT NULL UNIQUE
            )
            SQL);
        $this->addSql('CREATE INDEX audit_log_occurred_idx ON admin.audit_log (occurred_at DESC)');
        $this->addSql('CREATE INDEX audit_log_actor_idx ON admin.audit_log (actor_type, actor_id, occurred_at DESC)');
        $this->addSql('CREATE INDEX audit_log_subject_idx ON admin.audit_log (subject_type, subject_id, occurred_at DESC)');
        $this->addSql('CREATE TRIGGER audit_log_append_only BEFORE UPDATE OR DELETE ON admin.audit_log FOR EACH ROW EXECUTE FUNCTION shared.forbid_mutation()');
        // TRUNCATE contourne les triggers de ligne : on l'interdit aussi.
        $this->addSql('CREATE TRIGGER audit_log_no_truncate BEFORE TRUNCATE ON admin.audit_log FOR EACH STATEMENT EXECUTE FUNCTION shared.forbid_mutation()');
        $this->addSql('CREATE TRIGGER setting_history_no_truncate BEFORE TRUNCATE ON admin.setting_history FOR EACH STATEMENT EXECUTE FUNCTION shared.forbid_mutation()');

        $this->addSql(<<<'SQL'
            CREATE TABLE messenger_failed (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                body text NOT NULL,
                headers text NOT NULL,
                queue_name varchar(190) NOT NULL,
                created_at timestamp(0) NOT NULL,
                available_at timestamp(0) NOT NULL,
                delivered_at timestamp(0) NULL
            )
            SQL);
        $this->addSql('CREATE INDEX messenger_failed_queue_idx ON messenger_failed (queue_name, available_at, delivered_at, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE messenger_failed');
        $this->addSql('DROP TABLE admin.audit_log');
        $this->addSql('DROP TABLE admin.setting_history');
        $this->addSql('DROP TABLE admin.setting');
        $this->addSql('DROP TABLE shared.outbox_message');
        $this->addSql('DROP FUNCTION shared.forbid_mutation()');
        foreach (array_reverse(self::SCHEMAS) as $name) {
            $this->addSql(\sprintf('DROP SCHEMA IF EXISTS %s', $name));
        }
    }
}
