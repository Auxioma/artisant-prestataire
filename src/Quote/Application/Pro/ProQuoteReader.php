<?php

declare(strict_types=1);

namespace App\Quote\Application\Pro;

interface ProQuoteReader
{
    /** @return array{items: list<ProQuoteRow>, total: int} */
    public function page(string $artisanId, ProQuoteFilter $filter, int $page, int $perPage): array;

    /** @return array{pending: int, accepted: int, works: int, done: int, devis: int, chantiers: int} */
    public function stats(string $artisanId): array;

    /** @return array<string, string> métiers des demandes auxquelles l'artisan a répondu */
    public function jobs(string $artisanId): array;
}
