<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T-603 — abonnements des artisans (§4.3) : miroir de la Subscription Stripe, un seul abonnement en vigueur par
 * artisan ; les souscriptions abandonnées restent « incomplete ».
 */
final class Version20261122090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'billing.subscription';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE billing.subscription (
                id uuid PRIMARY KEY,
                artisan_id uuid NOT NULL REFERENCES billing.credit_account (artisan_id),
                plan_id uuid NOT NULL REFERENCES billing.plan (id),
                plan_price_id uuid NOT NULL REFERENCES billing.plan_price (id),
                interval varchar(5) NOT NULL CHECK (interval IN ('month', 'year')),
                status varchar(12) NOT NULL CHECK (status IN ('incomplete', 'active', 'past_due', 'suspended', 'cancelled')),
                stripe_subscription_id varchar(255) NULL UNIQUE,
                current_period_start timestamptz NULL,
                current_period_end timestamptz NULL,
                cancel_at_period_end boolean NOT NULL DEFAULT false,
                created_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL,
                version integer NOT NULL DEFAULT 1,
                CHECK (status = 'incomplete' OR status = 'cancelled' OR (stripe_subscription_id IS NOT NULL AND current_period_end > current_period_start))
            )
            SQL);
        $this->addSql("CREATE UNIQUE INDEX subscription_live_uniq ON billing.subscription (artisan_id) WHERE status IN ('active', 'past_due', 'suspended')");
        $this->addSql('CREATE INDEX subscription_plan_idx ON billing.subscription (plan_id, status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE billing.subscription');
    }
}
