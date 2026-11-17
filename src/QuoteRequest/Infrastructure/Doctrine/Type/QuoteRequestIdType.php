<?php

declare(strict_types=1);

namespace App\QuoteRequest\Infrastructure\Doctrine\Type;

use App\QuoteRequest\Domain\Request\QuoteRequestId;
use App\Shared\Infrastructure\Doctrine\Type\AggregateIdType;

final class QuoteRequestIdType extends AggregateIdType
{
    public const string NAME = 'quote_request_id';

    protected function idClass(): string
    {
        return QuoteRequestId::class;
    }
}
