<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Pro;

use App\QuoteRequest\Application\Port\ProRequestReader;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Domain\Time\Clock;

final readonly class AvailableRequestsHandler implements QueryHandler
{
    public function __construct(private ProRequestAccess $access, private ProRequestReader $reader, private Clock $clock)
    {
    }

    public function __invoke(AvailableRequests $query): ?ProRequestPage
    {
        $artisan = $this->access->artisan($query->userId);
        if (null === $artisan) {
            return null;
        }
        $page = max(1, $query->page);
        $result = $this->reader->page($artisan, $query->filter, $page, $query->perPage, $this->clock->now());

        return new ProRequestPage($artisan, $result['items'], $result['total'], $page, $query->perPage);
    }
}
