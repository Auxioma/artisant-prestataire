<?php

declare(strict_types=1);

namespace App\Billing\Application\Promo;

use App\Shared\Application\Bus\Command;

/** BO08.3 — création d'un code promo (champs du formulaire), synchronisé avec Stripe. */
final readonly class CreatePromoCode implements Command
{
    /** @param array<string, mixed> $input */
    public function __construct(public string $adminId, public string $id, public array $input)
    {
    }
}
