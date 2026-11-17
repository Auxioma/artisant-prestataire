<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T-702a — agenda de l'artisan (PR06, PR16, PR19) : réglages, plages récurrentes, absences, créneaux générés à l'avance.
 * Contrainte d'exclusion : deux créneaux d'un même artisan ne se chevauchent jamais (RG-025, verrou RG-073 au lot RDV).
 */
final class Version20261129090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'artisan.agenda_settings, availability_rule, availability_absence, availability_slot';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE EXTENSION IF NOT EXISTS btree_gist');
        $this->addSql(<<<'SQL'
            CREATE TABLE artisan.agenda_settings (
                artisan_id uuid PRIMARY KEY REFERENCES artisan.artisan_profile (id) ON DELETE CASCADE,
                slot_minutes smallint NOT NULL DEFAULT 60 CHECK (slot_minutes IN (60, 120, 240)),
                min_notice_hours smallint NOT NULL DEFAULT 24 CHECK (min_notice_hours BETWEEN 0 AND 168),
                closed_on_holidays boolean NOT NULL DEFAULT true,
                paused boolean NOT NULL DEFAULT false,
                ical_token_hash char(64) NULL UNIQUE,
                ical_token_created_at timestamptz NULL,
                updated_at timestamptz NOT NULL,
                CHECK ((ical_token_hash IS NULL) = (ical_token_created_at IS NULL))
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE artisan.availability_rule (
                id uuid PRIMARY KEY,
                artisan_id uuid NOT NULL REFERENCES artisan.artisan_profile (id) ON DELETE CASCADE,
                weekday smallint NOT NULL CHECK (weekday BETWEEN 1 AND 7),
                start_minute smallint NOT NULL CHECK (start_minute BETWEEN 0 AND 1440),
                end_minute smallint NOT NULL CHECK (end_minute BETWEEN 0 AND 1440),
                CHECK (start_minute < end_minute)
            )
            SQL);
        $this->addSql('CREATE INDEX availability_rule_artisan_idx ON artisan.availability_rule (artisan_id, weekday, start_minute)');
        $this->addSql(<<<'SQL'
            CREATE TABLE artisan.availability_absence (
                id uuid PRIMARY KEY,
                artisan_id uuid NOT NULL REFERENCES artisan.artisan_profile (id) ON DELETE CASCADE,
                starts_on date NOT NULL,
                ends_on date NOT NULL,
                kind varchar(15) NOT NULL CHECK (kind IN ('leave', 'unavailable')),
                label varchar(80) NULL,
                created_at timestamptz NOT NULL,
                CHECK (ends_on >= starts_on)
            )
            SQL);
        $this->addSql('CREATE INDEX availability_absence_artisan_idx ON artisan.availability_absence (artisan_id, ends_on)');
        $this->addSql(<<<'SQL'
            CREATE TABLE artisan.availability_slot (
                id uuid PRIMARY KEY,
                artisan_id uuid NOT NULL REFERENCES artisan.artisan_profile (id) ON DELETE CASCADE,
                starts_at timestamptz NOT NULL,
                ends_at timestamptz NOT NULL,
                status varchar(10) NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'held', 'booked', 'blocked')),
                held_until timestamptz NULL,
                appointment_id uuid NULL,
                CHECK (ends_at > starts_at),
                CHECK ((status = 'held') = (held_until IS NOT NULL)),
                EXCLUDE USING gist (artisan_id WITH =, tstzrange(starts_at, ends_at) WITH &&)
            )
            SQL);
        $this->addSql("CREATE INDEX availability_slot_open_idx ON artisan.availability_slot (artisan_id, starts_at) WHERE status = 'open'");
        $this->addSql("CREATE INDEX availability_slot_open_time_idx ON artisan.availability_slot (starts_at) WHERE status = 'open'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE artisan.availability_slot');
        $this->addSql('DROP TABLE artisan.availability_absence');
        $this->addSql('DROP TABLE artisan.availability_rule');
        $this->addSql('DROP TABLE artisan.agenda_settings');
    }
}
