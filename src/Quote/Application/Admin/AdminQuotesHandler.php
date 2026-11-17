<?php

declare(strict_types=1);

namespace App\Quote\Application\Admin;

use App\Shared\Application\Bus\QueryHandler;

final readonly class AdminQuotesHandler implements QueryHandler
{
    public function __construct(private AdminQuoteReader $reader)
    {
    }

    public function __invoke(AdminQuotes $query): AdminQuotesPage
    {
        return $this->reader->page($query);
    }
}
