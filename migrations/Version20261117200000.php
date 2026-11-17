<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** T-402e — « Créer mon compte plus tard » (Figma 5:162, P11.6) : compte sans mot de passe choisi, lien magique. */
final class Version20261117200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Comptes : identity.user_account.passwordless';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE identity.user_account ADD passwordless boolean NOT NULL DEFAULT false');
        $this->addSql('ALTER TABLE identity.user_account ALTER passwordless DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE identity.user_account DROP passwordless');
    }
}
