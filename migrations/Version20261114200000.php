<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** T-307a — P17 : mentions légales et politique de cookies versionnées comme les autres documents légaux. */
final class Version20261114200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Documents légaux : types legal_notice et cookies';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE identity.legal_document DROP CONSTRAINT legal_document_type_check');
        $this->addSql("ALTER TABLE identity.legal_document ADD CONSTRAINT legal_document_type_check CHECK (type IN ('cgu', 'cgv', 'cgv_pro', 'privacy', 'legal_notice', 'cookies'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM identity.legal_document WHERE type IN ('legal_notice', 'cookies')");
        $this->addSql('ALTER TABLE identity.legal_document DROP CONSTRAINT legal_document_type_check');
        $this->addSql("ALTER TABLE identity.legal_document ADD CONSTRAINT legal_document_type_check CHECK (type IN ('cgu', 'cgv', 'cgv_pro', 'privacy'))");
    }
}
