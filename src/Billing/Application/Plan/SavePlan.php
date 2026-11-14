<?php

declare(strict_types=1);

namespace App\Billing\Application\Plan;

use App\Shared\Application\Bus\Command;

/** BO08.1 — création (planId null) ou modification d'un forfait, prix compris (saisie en euros HT). */
final readonly class SavePlan implements Command
{
    /** @param array<string, mixed> $input champs du formulaire BO08 */
    public function __construct(public string $adminId, public ?string $planId, public string $newId, public array $input)
    {
    }
}
