<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T-604a — webhooks Stripe (ADR-0010) : événements reçus une seule fois (`processed_stripe_event`, clé = id Stripe) et
 * périodes d'abonnement payées (`subscription_period`, une par facture) qui portent les crédits de période (RG-109).
 */
final class Version20261123090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'billing.processed_stripe_event, billing.subscription_period';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE billing.processed_stripe_event (
                event_id varchar(255) PRIMARY KEY,
                type varchar(100) NOT NULL,
                livemode boolean NOT NULL,
                payload jsonb NOT NULL,
                payload_sha256 char(64) NOT NULL,
                status varchar(10) NOT NULL DEFAULT 'received' CHECK (status IN ('received', 'processed', 'ignored', 'failed')),
                attempts integer NOT NULL DEFAULT 0,
                last_error varchar(500) NULL,
                received_at timestamptz NOT NULL,
                processed_at timestamptz NULL
            )
            SQL);
        $this->addSql('CREATE INDEX processed_stripe_event_status_idx ON billing.processed_stripe_event (status, received_at)');
        $this->addSql(<<<'SQL'
            CREATE TABLE billing.subscription_period (
                id uuid PRIMARY KEY,
                subscription_id uuid NOT NULL REFERENCES billing.subscription (id),
                stripe_invoice_id varchar(255) NOT NULL UNIQUE,
                period_start timestamptz NOT NULL,
                period_end timestamptz NOT NULL CHECK (period_end > period_start),
                credits integer NOT NULL CHECK (credits >= 0),
                amount_paid_cents integer NOT NULL,
                created_at timestamptz NOT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX subscription_period_sub_idx ON billing.subscription_period (subscription_id, period_start)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE billing.subscription_period');
        $this->addSql('DROP TABLE billing.processed_stripe_event');
    }
}
