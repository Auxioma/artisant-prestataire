<?php

declare(strict_types=1);

namespace App\Billing\Application\Promo;

use App\Shared\Application\Bus\Command;

final readonly class DisablePromoCode implements Command
{
    public function __construct(public string $adminId, public string $id)
    {
    }
}
