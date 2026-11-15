<?php

declare(strict_types=1);

namespace App\Content\Application\Page;

/** Pages (`content.page`). */
interface PageStore
{
    public function create(string $id, string $slug, string $title, ?string $adminId, \DateTimeImmutable $at): void;

    public function find(string $id): ?PageView;

    public function findBySlug(string $slug): ?PageView;

    /** @return list<PageView> */
    public function all(): array;

    public function slugTaken(string $slug, ?string $exceptId): bool;

    public function saveMeta(string $id, string $slug, string $title, ?string $metaTitle, ?string $metaDescription, bool $noindex, ?string $adminId, \DateTimeImmutable $at): void;

    /** @param list<array{id: string, type: string, data: array<string, mixed>}> $blocks */
    public function saveDraft(string $id, array $blocks, ?string $adminId, \DateTimeImmutable $at): void;

    /** Publie le brouillon tout de suite (`$at` = maintenant) ou le programme (`$at` futur). */
    public function publish(string $id, \DateTimeImmutable $at, bool $scheduled, ?string $adminId, \DateTimeImmutable $now): void;

    public function unpublish(string $id, ?string $adminId, \DateTimeImmutable $now): void;

    public function delete(string $id): void;

    /** @return list<string> titres des pages dont une version (brouillon, en ligne, programmée) utilise l'image */
    public function usingMedia(string $mediaId): array;
}
