<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** T-403a — RG-063 et BO04.1 : source de la demande, empreinte d'IP (rafales), motif de refus normalisé. */
final class Version20261118090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Demandes : source, ip_hash ; index de modération et de rafale';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE request.quote_request ADD source varchar(10) NOT NULL DEFAULT 'other' CHECK (source IN ('direct', 'search', 'seo', 'home', 'other'))");
        $this->addSql('ALTER TABLE request.quote_request ALTER source DROP DEFAULT');
        $this->addSql('ALTER TABLE request.quote_request ADD ip_hash varchar(64) NULL');
        $this->addSql('CREATE INDEX quote_request_ip_idx ON request.quote_request (ip_hash, submitted_at) WHERE ip_hash IS NOT NULL');
        $this->addSql('CREATE INDEX quote_request_submitted_idx ON request.quote_request (submitted_at DESC)');
        $this->addSql("CREATE INDEX quote_request_moderation_idx ON request.quote_request (submitted_at) WHERE status = 'pending_moderation'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE request.quote_request DROP source, DROP ip_hash');
    }
}
