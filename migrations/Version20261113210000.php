<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T-211 — Billing (amorce, ADR-0006) : compte de crédits (ancre de verrou), registre en ajout seul (RG-107) avec
 * crédit gratuit unique (RG-044), solde dénormalisé pour l'affichage.
 */
final class Version20261113210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crédits : billing.credit_account, credit_ledger_entry, credit_balance';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SCHEMA IF NOT EXISTS billing');
        $this->addSql(<<<'SQL'
            CREATE TABLE billing.credit_account (
                artisan_id uuid PRIMARY KEY,
                user_id uuid NOT NULL UNIQUE,
                stripe_customer_id varchar(255) NULL UNIQUE,
                created_at timestamptz NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE billing.credit_ledger_entry (
                id uuid PRIMARY KEY,
                artisan_id uuid NOT NULL REFERENCES billing.credit_account (artisan_id),
                type varchar(20) NOT NULL CHECK (type IN ('free_grant', 'period_grant', 'quote_debit', 'refund', 'manual', 'expiry', 'pack_purchase')),
                bucket varchar(10) NOT NULL CHECK (bucket IN ('free', 'plan', 'extra')),
                amount integer NOT NULL CHECK (amount <> 0),
                expires_at timestamptz NULL,
                period_id uuid NULL,
                reference_type varchar(40) NULL,
                reference_id uuid NULL,
                admin_id uuid NULL,
                reason varchar(500) NULL,
                created_at timestamptz NOT NULL,
                CHECK (type <> 'manual' OR (admin_id IS NOT NULL AND length(reason) > 0)),
                CHECK (type <> 'free_grant' OR (bucket = 'free' AND amount > 0))
            )
            SQL);
        // RG-044 : un seul crédit gratuit par artisan ; idempotence des écritures liées à une référence.
        $this->addSql("CREATE UNIQUE INDEX credit_ledger_free_grant_uniq ON billing.credit_ledger_entry (artisan_id) WHERE type = 'free_grant'");
        $this->addSql("CREATE UNIQUE INDEX credit_ledger_reference_uniq ON billing.credit_ledger_entry (type, reference_type, reference_id) WHERE type IN ('quote_debit', 'refund', 'period_grant', 'expiry')");
        $this->addSql('CREATE INDEX credit_ledger_artisan_bucket_idx ON billing.credit_ledger_entry (artisan_id, bucket) INCLUDE (amount)');
        // RG-107 : registre en ajout seul (même protection que le journal d'audit).
        $this->addSql('CREATE TRIGGER credit_ledger_append_only BEFORE UPDATE OR DELETE ON billing.credit_ledger_entry FOR EACH ROW EXECUTE FUNCTION shared.forbid_mutation()');
        $this->addSql('CREATE TRIGGER credit_ledger_no_truncate BEFORE TRUNCATE ON billing.credit_ledger_entry FOR EACH STATEMENT EXECUTE FUNCTION shared.forbid_mutation()');
        $this->addSql(<<<'SQL'
            CREATE TABLE billing.credit_balance (
                artisan_id uuid PRIMARY KEY REFERENCES billing.credit_account (artisan_id),
                free integer NOT NULL DEFAULT 0 CHECK (free >= 0),
                plan integer NOT NULL DEFAULT 0 CHECK (plan >= 0),
                extra integer NOT NULL DEFAULT 0 CHECK (extra >= 0),
                free_granted integer NOT NULL DEFAULT 0 CHECK (free_granted >= 0),
                updated_at timestamptz NOT NULL
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE billing.credit_balance');
        $this->addSql('DROP TABLE billing.credit_ledger_entry');
        $this->addSql('DROP TABLE billing.credit_account');
    }
}
