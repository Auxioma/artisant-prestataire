<?php

declare(strict_types=1);

namespace App\Billing\Application\Promo;

use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Domain\Time\Clock;

final readonly class AdminPromoCodesHandler implements QueryHandler
{
    public function __construct(private PromoCodes $codes, private Clock $clock)
    {
    }

    /** @return list<PromoCodeView> */
    public function __invoke(AdminPromoCodes $query): array
    {
        return $this->codes->search($query->query, $query->state, $this->clock->now());
    }
}
