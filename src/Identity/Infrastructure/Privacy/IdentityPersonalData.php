<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Privacy;

use App\Shared\Application\Privacy\PersonalDataSource;
use App\Shared\Infrastructure\Persistence\PersonalDataRows;
use Doctrine\DBAL\Connection;

/** Compte et profil client (le mot de passe n'est jamais exporté, même haché). */
final readonly class IdentityPersonalData implements PersonalDataSource
{
    public function __construct(private Connection $connection)
    {
    }

    public function key(): string
    {
        return 'compte';
    }

    public function title(): string
    {
        return 'Compte et profil';
    }

    public function collect(string $userId): array
    {
        return PersonalDataRows::format($this->connection->fetchAllAssociative(<<<'SQL'
            SELECT u.first_name AS prenom, u.last_name AS nom, u.email AS e_mail, u.phone AS telephone, u.kind AS type_de_compte,
                   u.status AS statut, u.created_at, u.email_verified_at, u.last_login_at, u.password_changed_at,
                   p.type AS client, p.company_name AS raison_sociale, p.siret, p.postal_code AS code_postal,
                   p.search_radius_km AS rayon_de_recherche_km, p.interests AS domaines_d_interet,
                   p.newsletter_opt_in_at AS newsletter_acceptee_at
            FROM identity.user_account u LEFT JOIN identity.client_profile p ON p.user_id = u.id
            WHERE u.id = :u
            SQL, ['u' => $userId]), ['domaines_d_interet']);
    }
}
