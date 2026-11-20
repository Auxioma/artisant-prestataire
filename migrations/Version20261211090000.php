<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T-808a — durées de conservation (CDC §11) : demandes archivées (contenu effacé), purge du journal d'audit au-delà
 * de `audit.retention_months` avec point d'ancrage de la chaîne d'empreintes ; suppression autorisée seulement à la
 * tâche de rétention (variable de transaction `app.audit_retention`).
 */
final class Version20261211090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'request.quote_request.archived_at ; admin.audit_anchor ; purge contrôlée du journal d’audit';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE request.quote_request ADD archived_at timestamptz NULL');
        $this->addSql("CREATE INDEX quote_request_archivable_idx ON request.quote_request (updated_at) WHERE archived_at IS NULL AND status IN ('completed', 'expired', 'cancelled', 'rejected', 'awarded')");
        $this->addSql(<<<'SQL'
            CREATE TABLE admin.audit_anchor (
                id bigserial PRIMARY KEY,
                purged_through_seq bigint NOT NULL,
                last_hash char(64) NOT NULL,
                purged_count integer NOT NULL CHECK (purged_count > 0),
                purged_at timestamptz NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE FUNCTION admin.audit_log_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' AND current_setting('app.audit_retention', true) = 'on' THEN
                    RETURN OLD;
                END IF;
                RAISE EXCEPTION 'admin.audit_log : journal en ajout seul (seule la tâche de rétention peut purger)';
            END;
            $$
            SQL);
        $this->addSql('DROP TRIGGER audit_log_append_only ON admin.audit_log');
        $this->addSql('CREATE TRIGGER audit_log_append_only BEFORE UPDATE OR DELETE ON admin.audit_log FOR EACH ROW EXECUTE FUNCTION admin.audit_log_guard()');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER audit_log_append_only ON admin.audit_log');
        $this->addSql('CREATE TRIGGER audit_log_append_only BEFORE UPDATE OR DELETE ON admin.audit_log FOR EACH ROW EXECUTE FUNCTION shared.forbid_mutation()');
        $this->addSql('DROP FUNCTION admin.audit_log_guard()');
        $this->addSql('DROP TABLE admin.audit_anchor');
        $this->addSql('DROP INDEX request.quote_request_archivable_idx');
        $this->addSql('ALTER TABLE request.quote_request DROP archived_at');
    }
}
