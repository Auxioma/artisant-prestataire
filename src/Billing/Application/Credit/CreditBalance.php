<?php

declare(strict_types=1);

namespace App\Billing\Application\Credit;

final readonly class CreditBalance
{
    public function __construct(public int $free, public int $plan, public int $extra, public int $freeGranted)
    {
    }

    public function total(): int
    {
        return $this->free + $this->plan + $this->extra;
    }
}
