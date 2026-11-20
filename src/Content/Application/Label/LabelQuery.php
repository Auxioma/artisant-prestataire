<?php

declare(strict_types=1);

namespace App\Content\Application\Label;

use App\Shared\Application\Bus\Query;

/** Réponse : list<UiLabel> (`all`) ou array<string, string> des textes modifiés (`overrides`). */
final readonly class LabelQuery implements Query
{
    public function __construct(public bool $overridesOnly = false, public string $locale = LabelStore::LOCALE)
    {
    }
}
