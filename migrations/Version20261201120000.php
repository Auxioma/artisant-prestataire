<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** T-704b — invitations à donner son avis (E-16 : J+1, relance J+7 ; relance manuelle de l'artisan PR09.3, une fois). */
final class Version20261201120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'review.invitation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE review.invitation (
                request_id uuid PRIMARY KEY REFERENCES request.quote_request (id) ON DELETE CASCADE,
                invited_at timestamptz NULL,
                reminded_at timestamptz NULL,
                artisan_reminded_at timestamptz NULL
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE review.invitation');
    }
}
