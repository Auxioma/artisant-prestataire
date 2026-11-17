<?php

declare(strict_types=1);

namespace App\Quote\Infrastructure\Persistence;

use App\Quote\Application\Port\QuoteNumberAllocator;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class DbalQuoteNumberAllocator implements QuoteNumberAllocator
{
    public function __construct(private Connection $connection)
    {
    }

    public function next(string $artisanId, int $year): int
    {
        return DbalValue::int($this->connection->fetchOne(
            'INSERT INTO quote.quote_number_sequence (artisan_id, year, last) VALUES (:a, :y, 1)
             ON CONFLICT (artisan_id, year) DO UPDATE SET last = quote.quote_number_sequence.last + 1 RETURNING last',
            ['a' => $artisanId, 'y' => $year],
        ));
    }
}
