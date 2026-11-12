<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** T-403b — BO05 « Contenus de profils » : décision de relecture (RG-031). */
final class Version20261118120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Relecture des profils : reviewed_at, reviewed_by, reason';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE artisan.profile_review ADD reviewed_at timestamptz NULL, ADD reviewed_by uuid NULL, ADD reason varchar(30) NULL');
        $this->addSql("ALTER TABLE artisan.profile_review ADD CONSTRAINT profile_review_decided_ck CHECK ((status = 'pending') = (reviewed_at IS NULL))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE artisan.profile_review DROP CONSTRAINT profile_review_decided_ck, DROP reviewed_at, DROP reviewed_by, DROP reason');
    }
}
