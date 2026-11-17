<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Admin;

use App\Shared\Application\Admin\PendingRequestModerations;
use App\Shared\Application\Bus\QueryHandler;

final readonly class AdminRequestListHandler implements QueryHandler
{
    public function __construct(private AdminRequestRows $rows)
    {
    }

    public function __invoke(AdminRequestList|PendingRequestModerations $query): AdminRequestPage|int
    {
        if ($query instanceof PendingRequestModerations) {
            return $this->rows->page(new AdminRequestList(status: 'pending_moderation', perPage: 1))->kpis['pending'];
        }

        return $this->rows->page($query);
    }
}
