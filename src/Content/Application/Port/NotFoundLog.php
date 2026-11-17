<?php

declare(strict_types=1);

namespace App\Content\Application\Port;

/** BO12.2 — rapport des 404 : chemins demandés introuvables (sans donnée personnelle). */
interface NotFoundLog
{
    public function record(string $path, ?string $referer, \DateTimeImmutable $at): void;

    /** @return list<array{path: string, hits: int, last_referer: ?string, last_seen_at: string}> */
    public function top(int $limit): array;

    public function dismiss(string $path): void;
}
