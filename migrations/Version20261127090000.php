<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T-701a — messagerie C04 / PR07 (schéma `messaging`) : conversations (une par demande et artisan, une question préalable
 * par client et artisan), messages en ajout seul (texte masqué RG-080, original scellé ADR-0014, signalement), état de
 * chaque participant (dernière lecture, archivage, dernier e-mail E-17 pour le regroupement).
 */
final class Version20261127090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'messaging.conversation, messaging.message, messaging.participant';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SCHEMA IF NOT EXISTS messaging');
        $this->addSql(<<<'SQL'
            CREATE TABLE messaging.conversation (
                id uuid PRIMARY KEY,
                kind varchar(15) NOT NULL CHECK (kind IN ('request', 'pre_question')),
                request_id uuid NULL,
                client_user_id uuid NOT NULL,
                artisan_id uuid NOT NULL,
                artisan_user_id uuid NOT NULL,
                started_by uuid NOT NULL,
                blocked_by uuid NULL,
                last_message_at timestamptz NULL,
                created_at timestamptz NOT NULL,
                CHECK ((kind = 'request') = (request_id IS NOT NULL)),
                CHECK (client_user_id <> artisan_user_id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX conversation_request_uniq ON messaging.conversation (request_id, artisan_id) WHERE request_id IS NOT NULL');
        $this->addSql("CREATE UNIQUE INDEX conversation_pre_question_uniq ON messaging.conversation (client_user_id, artisan_id) WHERE kind = 'pre_question'");
        $this->addSql('CREATE INDEX conversation_client_idx ON messaging.conversation (client_user_id, last_message_at DESC)');
        $this->addSql('CREATE INDEX conversation_artisan_idx ON messaging.conversation (artisan_user_id, last_message_at DESC)');
        $this->addSql(<<<'SQL'
            CREATE TABLE messaging.message (
                id uuid PRIMARY KEY,
                conversation_id uuid NOT NULL REFERENCES messaging.conversation (id) ON DELETE CASCADE,
                sender_user_id uuid NOT NULL,
                body text NOT NULL,
                original_sealed text NULL,
                created_at timestamptz NOT NULL,
                reported_by uuid NULL,
                reported_at timestamptz NULL,
                report_reason varchar(30) NULL,
                CHECK ((reported_at IS NULL) = (reported_by IS NULL))
            )
            SQL);
        $this->addSql('CREATE INDEX message_conversation_idx ON messaging.message (conversation_id, created_at)');
        $this->addSql('CREATE INDEX message_reported_idx ON messaging.message (reported_at) WHERE reported_at IS NOT NULL');
        // Messages en ajout seul : seul le signalement (colonnes reported_*) peut évoluer.
        $this->addSql(<<<'SQL'
            CREATE FUNCTION messaging.forbid_message_edit() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.body IS DISTINCT FROM OLD.body OR NEW.original_sealed IS DISTINCT FROM OLD.original_sealed
                   OR NEW.sender_user_id IS DISTINCT FROM OLD.sender_user_id OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'Un message ne se modifie pas.';
                END IF;
                RETURN NEW;
            END $$
            SQL);
        $this->addSql('CREATE TRIGGER message_append_only BEFORE UPDATE ON messaging.message FOR EACH ROW EXECUTE FUNCTION messaging.forbid_message_edit()');
        $this->addSql(<<<'SQL'
            CREATE TABLE messaging.participant (
                conversation_id uuid NOT NULL REFERENCES messaging.conversation (id) ON DELETE CASCADE,
                user_id uuid NOT NULL,
                last_read_at timestamptz NULL,
                archived boolean NOT NULL DEFAULT false,
                last_notified_at timestamptz NULL,
                PRIMARY KEY (conversation_id, user_id)
            )
            SQL);
        $this->addSql('CREATE INDEX participant_user_idx ON messaging.participant (user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP SCHEMA messaging CASCADE');
    }
}
