<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BO06 / P11.2 — propositions de métier (et de catégorie) saisies par les clients dans le tunnel de demande : le
 * métier est créé inactif (invisible), la demande attend la décision du back-office (ajout, rattachement à un métier
 * existant ou refus). Clés différées : le métier (ORM) est écrit au flush, après la proposition (DBAL).
 */
final class Version20261218090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'catalog.job_proposal';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE catalog.job_proposal (
                id uuid PRIMARY KEY,
                job_id uuid NOT NULL REFERENCES catalog.job (id) DEFERRABLE INITIALLY DEFERRED,
                category_id uuid NOT NULL REFERENCES catalog.category (id) DEFERRABLE INITIALLY DEFERRED,
                new_category boolean NOT NULL,
                proposed_job_name varchar(80) NOT NULL,
                proposed_category_name varchar(80) DEFAULT NULL,
                user_id uuid DEFAULT NULL,
                status varchar(16) NOT NULL CHECK (status IN ('pending', 'approved', 'merged', 'rejected')),
                final_job_id uuid DEFAULT NULL REFERENCES catalog.job (id),
                note varchar(300) DEFAULT NULL,
                decided_by uuid DEFAULT NULL,
                decided_at timestamptz DEFAULT NULL,
                created_at timestamptz NOT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX job_proposal_status_idx ON catalog.job_proposal (status, created_at)');
        $this->addSql('CREATE INDEX job_proposal_job_idx ON catalog.job_proposal (job_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE catalog.job_proposal');
    }
}
