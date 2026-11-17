<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** T-602 — prix remplacés retirés de la vente chez Stripe (`stripe_retired_at`) ; identifiants Stripe uniques. */
final class Version20261121090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'billing.plan_price.stripe_retired_at, unicité des identifiants Stripe';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE billing.plan_price ADD stripe_retired_at timestamptz NULL');
        $this->addSql('CREATE UNIQUE INDEX plan_price_stripe_uniq ON billing.plan_price (stripe_price_id) WHERE stripe_price_id IS NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX plan_stripe_product_uniq ON billing.plan (stripe_product_id) WHERE stripe_product_id IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX billing.plan_stripe_product_uniq');
        $this->addSql('DROP INDEX billing.plan_price_stripe_uniq');
        $this->addSql('ALTER TABLE billing.plan_price DROP stripe_retired_at');
    }
}
