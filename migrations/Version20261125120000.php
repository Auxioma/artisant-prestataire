<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T-606b — avoirs (Stripe Credit Notes) émis lors d'un remboursement total ou partiel depuis le back-office (rôle
 * Finance, ADR-0009) ou depuis Stripe ; E-48 envoyé une seule fois par avoir.
 */
final class Version20261125120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'billing.credit_note';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE billing.credit_note (
                id uuid PRIMARY KEY,
                invoice_id uuid NOT NULL REFERENCES billing.invoice (id),
                artisan_id uuid NOT NULL REFERENCES billing.credit_account (artisan_id),
                stripe_credit_note_id varchar(255) NOT NULL UNIQUE,
                number varchar(60) NULL,
                amount_cents integer NOT NULL CHECK (amount_cents > 0),
                refunded_cents integer NOT NULL DEFAULT 0 CHECK (refunded_cents >= 0),
                reason varchar(30) NULL,
                comment varchar(500) NULL,
                admin_id uuid NULL,
                pdf_url text NULL,
                created_at timestamptz NOT NULL,
                notified_at timestamptz NULL
            )
            SQL);
        $this->addSql('CREATE INDEX credit_note_invoice_idx ON billing.credit_note (invoice_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE billing.credit_note');
    }
}
