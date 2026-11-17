<?php

declare(strict_types=1);

namespace App\Quote\Application\Admin;

interface AdminQuoteReader
{
    public function page(AdminQuotes $query): AdminQuotesPage;
}
