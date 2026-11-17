<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** T-506b — relance du client par l'artisan (RG-121, P-11). Les devis à expirer utilisent `quote_valid_until_idx` (T-501). */
final class Version20261119170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'quote.quote : reminder_count, reminded_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quote.quote ADD reminder_count integer NOT NULL DEFAULT 0 CHECK (reminder_count >= 0), ADD reminded_at timestamptz');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quote.quote DROP reminder_count, DROP reminded_at');
    }
}
