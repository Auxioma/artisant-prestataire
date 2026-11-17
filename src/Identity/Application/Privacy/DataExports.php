<?php

declare(strict_types=1);

namespace App\Identity\Application\Privacy;

/** Demandes d'export de données (`identity.data_export`). */
interface DataExports
{
    public function create(string $id, string $userId, \DateTimeImmutable $at): void;

    public function latest(string $userId): ?DataExportView;

    public function find(string $id): ?DataExportView;

    public function markReady(string $id, string $fileId, \DateTimeImmutable $at, \DateTimeImmutable $expiresAt): void;

    public function markFailed(string $id): void;

    /** @return list<array{id: string, fileId: ?string}> archives prêtes dont le lien a expiré */
    public function expiredBefore(\DateTimeImmutable $now): array;

    public function markExpired(string $id): void;

    /**
     * RG-090 : supprime les demandes d'export d'un compte effacé.
     *
     * @return list<string> fichiers encore présents, à détruire
     */
    public function forget(string $userId): array;
}
