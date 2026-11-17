<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Port;

/** RG-031 — contenus de profil retenus pour une relecture humaine aléatoire (file BO05). */
interface ProfileReviews
{
    /** @param array<string, mixed> $content */
    public function record(string $id, string $profileId, string $section, array $content, \DateTimeImmutable $at): void;

    /**
     * BO05 — échantillon à relire, plus anciens d'abord.
     *
     * @return list<array{id: string, artisan_id: string, artisan_name: string, section: string, content: array<string, mixed>, created_at: \DateTimeImmutable}>
     */
    public function pending(int $limit): array;

    /** @return array{artisan_id: string, section: string, content: array<string, mixed>, status: string}|null */
    public function find(string $id): ?array;

    public function decide(string $id, bool $approved, string $adminId, ?string $reason, \DateTimeImmutable $at): void;
}
