<?php

declare(strict_types=1);

namespace App\Content\Application\Port;

use App\Content\Application\Home\HomeVersion;

/**
 * Versions de la page d'accueil (BO10.1) : au plus un brouillon ; une version publiée porte sa date de publication
 * (future = programmée). La version en ligne est la plus récente dont la date est passée.
 */
interface HomeVersions
{
    public function draft(): ?HomeVersion;

    public function live(\DateTimeImmutable $now): ?HomeVersion;

    public function scheduled(\DateTimeImmutable $now): ?HomeVersion;

    public function find(string $id): ?HomeVersion;

    /** @return list<HomeVersion> versions publiées ou programmées, la plus récente d'abord */
    public function history(int $limit): array;

    /** Crée ou met à jour le brouillon. */
    public function saveDraft(HomeVersion $draft): void;

    public function publish(string $id, \DateTimeImmutable $publishAt, string $adminId): void;

    public function delete(string $id): void;

    public function nextNumber(): int;
}
