<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Domain\Profile;

use App\Shared\Domain\ValueObject\Siret;

interface ArtisanProfileRepository
{
    public function findByUserId(string $userId): ?ArtisanProfile;

    public function find(ArtisanProfileId $id): ?ArtisanProfile;

    /** RG-041 : un SIRET = un compte professionnel. */
    public function isSiretTaken(Siret $siret, ?ArtisanProfileId $except = null): bool;

    public function add(ArtisanProfile $profile): void;

    /**
     * BO03 — dossiers en attente de vérification, plus anciens d’abord.
     *
     * @return list<ArtisanProfile>
     */
    public function pendingVerification(int $limit): array;
}
