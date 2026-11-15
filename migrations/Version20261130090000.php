<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T-703a — rendez-vous d'un devis accepté (C03.6, PR06.2) : un seul rendez-vous proposé ou confirmé par devis, verrou
 * RG-073 (`hold_until`), annulation motivée, report, réalisation, rappel J-1 (E-15).
 */
final class Version20261130090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'quote.appointment';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE quote.appointment (
                id uuid PRIMARY KEY,
                quote_id uuid NOT NULL REFERENCES quote.quote (id) ON DELETE CASCADE,
                starts_at timestamptz NOT NULL,
                ends_at timestamptz NOT NULL,
                status varchar(12) NOT NULL CHECK (status IN ('proposed', 'confirmed', 'completed', 'cancelled', 'rescheduled', 'expired')),
                proposed_by varchar(8) NOT NULL CHECK (proposed_by IN ('client', 'artisan')),
                hold_until timestamptz NULL,
                note varchar(500) NULL,
                confirmed_at timestamptz NULL,
                completed_at timestamptz NULL,
                cancelled_at timestamptz NULL,
                cancelled_by varchar(8) NULL CHECK (cancelled_by IN ('client', 'artisan')),
                cancel_reason varchar(300) NULL,
                reminded_at timestamptz NULL,
                created_at timestamptz NOT NULL,
                CHECK (ends_at > starts_at),
                CHECK ((status = 'proposed') = (hold_until IS NOT NULL)),
                CHECK (status <> 'cancelled' OR cancel_reason IS NOT NULL)
            )
            SQL);
        $this->addSql("CREATE UNIQUE INDEX appointment_active_uniq ON quote.appointment (quote_id) WHERE status IN ('proposed', 'confirmed')");
        $this->addSql("CREATE INDEX appointment_hold_idx ON quote.appointment (hold_until) WHERE status = 'proposed'");
        $this->addSql("CREATE INDEX appointment_reminder_idx ON quote.appointment (starts_at) WHERE status = 'confirmed' AND reminded_at IS NULL");
        $this->addSql('CREATE INDEX appointment_quote_idx ON quote.appointment (quote_id, created_at DESC)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE quote.appointment');
    }
}
