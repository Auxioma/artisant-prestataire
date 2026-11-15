<?php

declare(strict_types=1);

namespace App\Administration\Application\Settings;

use App\Shared\Application\Bus\Query;

/** BO13 — réglages d'une rubrique et journal de leurs modifications (réponse : SettingsPageView). */
final readonly class SettingsPage implements Query
{
    public function __construct(public string $rubric)
    {
    }
}
