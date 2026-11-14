<?php

declare(strict_types=1);

namespace App\Billing\Application\Promo;

use App\Shared\Application\Bus\Query;

/** BO08.3 — liste filtrée ; réponse : list<PromoCodeView>. */
final readonly class AdminPromoCodes implements Query
{
    public function __construct(public string $query = '', public ?string $state = null)
    {
    }
}
