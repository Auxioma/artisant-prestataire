<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T-607 — rapprochement quotidien Stripe ↔ base (ADR-0010) et contrôle nocturne des soldes du registre (ADR-0006) :
 * écarts ouverts (un seul par type et référence, résolus automatiquement quand ils disparaissent ou à la main) et
 * passages (horodatage, éléments vérifiés, écarts) pour le back-office.
 */
final class Version20261126090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'billing.reconciliation_issue, billing.reconciliation_run';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE billing.reconciliation_issue (
                id uuid PRIMARY KEY,
                kind varchar(40) NOT NULL,
                reference varchar(255) NOT NULL,
                details text NOT NULL,
                detected_at timestamptz NOT NULL,
                last_seen_at timestamptz NOT NULL,
                resolved_at timestamptz NULL,
                resolved_by uuid NULL,
                resolution varchar(10) NULL CHECK (resolution IN ('auto', 'manual'))
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX reconciliation_issue_open_uniq ON billing.reconciliation_issue (kind, reference) WHERE resolved_at IS NULL');
        $this->addSql(<<<'SQL'
            CREATE TABLE billing.reconciliation_run (
                id uuid PRIMARY KEY,
                started_at timestamptz NOT NULL,
                finished_at timestamptz NOT NULL,
                stripe_checked boolean NOT NULL,
                checked integer NOT NULL,
                issues integer NOT NULL
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE billing.reconciliation_run');
        $this->addSql('DROP TABLE billing.reconciliation_issue');
    }
}
