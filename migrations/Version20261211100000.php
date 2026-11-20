<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T-808a — un message reste non modifiable, sauf effacement RGPD d'un compte supprimé (RG-090) : texte remplacé,
 * original scellé retiré, autorisé seulement dans la transaction qui pose `app.message_scrub`.
 */
final class Version20261211100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'messaging.forbid_message_edit : exception d’effacement RGPD';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION messaging.forbid_message_edit() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF current_setting('app.message_scrub', true) = 'on' THEN
                    RETURN NEW;
                END IF;
                IF NEW.body IS DISTINCT FROM OLD.body OR NEW.original_sealed IS DISTINCT FROM OLD.original_sealed
                   OR NEW.sender_user_id IS DISTINCT FROM OLD.sender_user_id OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'Un message ne se modifie pas.';
                END IF;
                RETURN NEW;
            END $$
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION messaging.forbid_message_edit() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.body IS DISTINCT FROM OLD.body OR NEW.original_sealed IS DISTINCT FROM OLD.original_sealed
                   OR NEW.sender_user_id IS DISTINCT FROM OLD.sender_user_id OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'Un message ne se modifie pas.';
                END IF;
                RETURN NEW;
            END $$
            SQL);
    }
}
