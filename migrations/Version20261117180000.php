<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** T-402d — compte créé dans le tunnel (Figma 5:162) : compte en attente d'activation et envoi demandé (RG-040). */
final class Version20261117180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Brouillons : pending_user_id, submit_requested_at';
    }

    public function up(Schema $schema): void
    {
        // Sans clé étrangère : l'identifiant est réservé même si l'adresse était déjà inscrite (anti-énumération, A02).
        $this->addSql('ALTER TABLE request.request_draft ADD pending_user_id uuid NULL, ADD submit_requested_at timestamptz NULL');
        $this->addSql('CREATE INDEX request_draft_pending_user_idx ON request.request_draft (pending_user_id) WHERE pending_user_id IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE request.request_draft DROP pending_user_id, DROP submit_requested_at');
    }
}
