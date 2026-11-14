<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** T-603c — changement de forfait programmé à l'échéance (descente, annuel → mensuel), affiché sur PR11. */
final class Version20261123150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'billing.subscription.scheduled_price_id, scheduled_on';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE billing.subscription ADD scheduled_price_id uuid NULL REFERENCES billing.plan_price (id), ADD scheduled_on timestamptz NULL');
        $this->addSql('ALTER TABLE billing.subscription ADD CONSTRAINT subscription_scheduled_chk CHECK ((scheduled_price_id IS NULL) = (scheduled_on IS NULL))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE billing.subscription DROP scheduled_price_id, DROP scheduled_on');
    }
}
