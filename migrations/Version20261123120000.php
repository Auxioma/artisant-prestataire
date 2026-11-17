<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** T-604b — début du retard de paiement (point de départ de la période de grâce, billing.grace_days). */
final class Version20261123120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'billing.subscription.past_due_since';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE billing.subscription ADD past_due_since timestamptz NULL');
        $this->addSql("CREATE INDEX subscription_past_due_idx ON billing.subscription (past_due_since) WHERE status IN ('past_due', 'suspended')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE billing.subscription DROP past_due_since');
    }
}
