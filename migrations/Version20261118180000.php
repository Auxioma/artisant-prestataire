<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** T-405a — PR02 « Ignorer » : motif facultatif (ciblage) ; liste PR02 filtrée sur les demandes non ignorées. */
final class Version20261118180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'request_dispatch.ignore_reason';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE request.request_dispatch ADD ignore_reason varchar(20) NULL CHECK (ignore_reason IN ('not_my_job', 'too_far', 'budget', 'no_time', 'other'))");
        $this->addSql('ALTER TABLE request.request_dispatch ADD CONSTRAINT request_dispatch_ignore_ck CHECK (ignore_reason IS NULL OR ignored_at IS NOT NULL)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE request.request_dispatch DROP CONSTRAINT request_dispatch_ignore_ck, DROP ignore_reason');
    }
}
