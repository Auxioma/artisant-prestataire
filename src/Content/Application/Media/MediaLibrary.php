<?php

declare(strict_types=1);

namespace App\Content\Application\Media;

/** Médiathèque (`content.media`). */
interface MediaLibrary
{
    public function add(string $id, string $fileId, string $title, string $alt, string $focal, ?string $adminId, \DateTimeImmutable $at): void;

    public function find(string $id): ?MediaView;

    public function findByFile(string $fileId): ?MediaView;

    /** @return list<MediaView> du plus récent au plus ancien */
    public function all(): array;

    public function update(string $id, string $title, string $alt, string $focal, \DateTimeImmutable $at): void;

    /** @param array<string, array<int, string>> $variants */
    public function markReady(string $id, int $width, int $height, array $variants, \DateTimeImmutable $at): void;

    public function markFailed(string $id, \DateTimeImmutable $at): void;

    public function delete(string $id): void;
}
