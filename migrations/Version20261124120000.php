<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** T-605b — E-46 (carte bientôt expirée) envoyé une seule fois par carte : remis à zéro quand la carte change. */
final class Version20261124120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'billing.payment_method.expiry_notified_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE billing.payment_method ADD expiry_notified_at timestamptz NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE billing.payment_method DROP expiry_notified_at');
    }
}
