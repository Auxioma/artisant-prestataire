<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** T-402c — alignement Figma P11 : titre, besoin en bref, nature des travaux ; créneau « week-end » retiré (Figma 5:110). */
final class Version20261117150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Demandes : title, summary, work_nature';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE request.quote_request ADD title varchar(100) NULL, ADD summary varchar(300) NULL, ADD work_nature varchar(120) NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE request.quote_request DROP title, DROP summary, DROP work_nature');
    }
}
