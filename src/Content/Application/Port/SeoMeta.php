<?php

declare(strict_types=1);

namespace App\Content\Application\Port;

/** BO12.1 — gabarits title / description par type de page et surcharges page par page. */
interface SeoMeta
{
    /** @return array<string, array{title: ?string, description: ?string}> */
    public function templates(): array;

    public function saveTemplate(string $pageType, ?string $title, ?string $description, string $adminId, \DateTimeImmutable $at): void;

    /** @return list<array{path: string, title: ?string, description: ?string, noindex: bool}> */
    public function overrides(): array;

    /** @return array{path: string, title: ?string, description: ?string, noindex: bool}|null */
    public function override(string $path): ?array;

    public function saveOverride(string $path, ?string $title, ?string $description, bool $noindex, string $adminId, \DateTimeImmutable $at): void;

    public function deleteOverride(string $path): void;
}
