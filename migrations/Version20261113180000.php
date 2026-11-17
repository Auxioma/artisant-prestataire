<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** T-210 — BO03 : contrôle des justificatifs (auteur, date, motif) et journal des décisions de vérification (RG-130, append-only). */
final class Version20261113180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Vérification artisans : contrôle des pièces et artisan.verification_decision';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE artisan.document
                ALTER reason TYPE varchar(200),
                ADD reviewed_by uuid NULL,
                ADD reviewed_at timestamptz NULL,
                ADD CONSTRAINT document_reviewed_ck CHECK (status NOT IN ('approved', 'rejected') OR reason = 'antivirus' OR (reviewed_by IS NOT NULL AND reviewed_at IS NOT NULL)),
                ADD CONSTRAINT document_rejected_reason_ck CHECK (status <> 'rejected' OR reason IS NOT NULL)
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE artisan.verification_decision (
                id uuid PRIMARY KEY,
                artisan_id uuid NOT NULL REFERENCES artisan.artisan_profile (id) DEFERRABLE INITIALLY DEFERRED,
                admin_id uuid NOT NULL,
                decision varchar(20) NOT NULL CHECK (decision IN ('approved', 'rejected', 'more_info')),
                reason_code varchar(40) NULL,
                comment varchar(500) NULL,
                created_at timestamptz NOT NULL,
                CHECK (decision <> 'rejected' OR reason_code IS NOT NULL),
                CHECK (decision <> 'more_info' OR comment IS NOT NULL)
            )
            SQL);
        $this->addSql('CREATE INDEX verification_decision_artisan_idx ON artisan.verification_decision (artisan_id, created_at)');
        // RG-130 : décisions horodatées, jamais modifiées ni supprimées.
        $this->addSql('CREATE TRIGGER verification_decision_append_only BEFORE UPDATE OR DELETE ON artisan.verification_decision FOR EACH ROW EXECUTE FUNCTION shared.forbid_mutation()');
        $this->addSql('CREATE TRIGGER verification_decision_no_truncate BEFORE TRUNCATE ON artisan.verification_decision FOR EACH STATEMENT EXECUTE FUNCTION shared.forbid_mutation()');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE artisan.verification_decision');
        $this->addSql('ALTER TABLE artisan.document DROP CONSTRAINT document_reviewed_ck, DROP CONSTRAINT document_rejected_reason_ck, DROP reviewed_by, DROP reviewed_at');
    }
}
