<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** T-406b — compléments d'une demande (C02 / RG-066 : après le premier devis, ajout d'un complément uniquement). */
final class Version20261118220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'request.request_addendum';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE request.request_addendum (
                id uuid PRIMARY KEY,
                request_id uuid NOT NULL REFERENCES request.quote_request (id) ON DELETE CASCADE,
                body text NOT NULL CHECK (char_length(body) BETWEEN 10 AND 1000),
                created_at timestamptz NOT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX request_addendum_request_idx ON request.request_addendum (request_id, created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE request.request_addendum');
    }
}
