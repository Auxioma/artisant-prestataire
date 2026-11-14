<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T-605a — réplique des factures Stripe (ADR-0009 : numéro, montants HT / TVA / TTC, statut, liens, copie PDF archivée
 * 10 ans avec empreinte) et moyen de paiement par défaut de chaque artisan (carte : 4 derniers chiffres et expiration).
 */
final class Version20261124090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'billing.invoice, billing.payment_method';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE billing.invoice (
                id uuid PRIMARY KEY,
                artisan_id uuid NOT NULL REFERENCES billing.credit_account (artisan_id),
                stripe_invoice_id varchar(255) NOT NULL UNIQUE,
                stripe_subscription_id varchar(255) NULL,
                number varchar(60) NULL,
                status varchar(15) NOT NULL CHECK (status IN ('draft', 'open', 'paid', 'uncollectible', 'void')),
                currency char(3) NOT NULL,
                amount_ht_cents integer NOT NULL,
                vat_cents integer NOT NULL,
                amount_ttc_cents integer NOT NULL,
                amount_paid_cents integer NOT NULL DEFAULT 0,
                period_start timestamptz NULL,
                period_end timestamptz NULL,
                issued_at timestamptz NOT NULL,
                paid_at timestamptz NULL,
                hosted_url text NULL,
                pdf_key varchar(255) NULL,
                pdf_sha256 char(64) NULL,
                archived_at timestamptz NULL,
                updated_at timestamptz NOT NULL,
                CHECK ((pdf_key IS NULL) = (pdf_sha256 IS NULL))
            )
            SQL);
        $this->addSql('CREATE INDEX invoice_artisan_idx ON billing.invoice (artisan_id, issued_at DESC)');
        $this->addSql(<<<'SQL'
            CREATE TABLE billing.payment_method (
                artisan_id uuid PRIMARY KEY REFERENCES billing.credit_account (artisan_id),
                type varchar(20) NOT NULL CHECK (type IN ('card', 'sepa_debit', 'other')),
                brand varchar(30) NULL,
                last4 char(4) NULL,
                exp_month smallint NULL CHECK (exp_month BETWEEN 1 AND 12),
                exp_year smallint NULL,
                updated_at timestamptz NOT NULL
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE billing.payment_method');
        $this->addSql('DROP TABLE billing.invoice');
    }
}
