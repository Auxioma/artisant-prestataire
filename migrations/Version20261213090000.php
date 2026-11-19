<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** T-808b1 — CDC §11 : compte inactif → relance (E-09b) puis anonymisation ; date de la relance, effacée à la connexion. */
final class Version20261213090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Relance des comptes inactifs (§11)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE identity.user_account ADD inactivity_notice_at timestamptz NULL');
        $this->addSql('CREATE INDEX user_account_inactivity_notice_idx ON identity.user_account (inactivity_notice_at) WHERE inactivity_notice_at IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX identity.user_account_inactivity_notice_idx');
        $this->addSql('ALTER TABLE identity.user_account DROP inactivity_notice_at');
    }
}
