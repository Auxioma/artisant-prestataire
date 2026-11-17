<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** T-402b — P11 étapes 4 à 6 : créneaux, statut, préférence de contact, adresse chiffrée (RG-022bis), pièces de la demande. */
final class Version20261117120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Demandes : créneaux, statut, préférence de contact ; request.request_address, request.request_attachment';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE request.quote_request ADD preferred_slots jsonb NOT NULL DEFAULT '[]'::jsonb");
        $this->addSql("ALTER TABLE request.quote_request ADD occupant_status varchar(10) NULL CHECK (occupant_status IN ('owner', 'tenant', 'syndic', 'company'))");
        $this->addSql("ALTER TABLE request.quote_request ADD contact_preference varchar(30) NOT NULL DEFAULT 'messaging_only' CHECK (contact_preference IN ('messaging_only', 'phone_after_acceptance'))");
        $this->addSql('ALTER TABLE request.quote_request ALTER preferred_slots DROP DEFAULT');
        $this->addSql('ALTER TABLE request.quote_request ALTER contact_preference DROP DEFAULT');
        $this->addSql(<<<'SQL'
            CREATE TABLE request.request_address (
                request_id uuid PRIMARY KEY REFERENCES request.quote_request (id) DEFERRABLE INITIALLY DEFERRED, -- agrégat écrit au flush
                address_sealed text NOT NULL,
                location geography(Point, 4326) NULL,
                geocoded boolean NOT NULL,
                created_at timestamptz NOT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX request_address_location_idx ON request.request_address USING gist (location)');
        $this->addSql(<<<'SQL'
            CREATE TABLE request.request_attachment (
                request_id uuid NOT NULL REFERENCES request.quote_request (id) DEFERRABLE INITIALLY DEFERRED,
                file_id uuid NOT NULL UNIQUE REFERENCES shared.stored_file (id),
                original_name varchar(120) NOT NULL,
                position smallint NOT NULL,
                PRIMARY KEY (request_id, file_id)
            )
            SQL);
        $this->addSql('CREATE INDEX request_attachment_position_idx ON request.request_attachment (request_id, position)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE request.request_attachment');
        $this->addSql('DROP TABLE request.request_address');
        $this->addSql('ALTER TABLE request.quote_request DROP preferred_slots, DROP occupant_status, DROP contact_preference');
    }
}
