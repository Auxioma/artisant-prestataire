<?php

declare(strict_types=1);

namespace App\Content\Application\Label;

use App\Shared\Application\Bus\Command;

/** BO10.6 — modifier un libellé (`text`) ou revenir au texte d'origine (`text` null). */
final readonly class LabelCommand implements Command
{
    public function __construct(public string $key, public ?string $text, public ?string $adminId, public string $locale = LabelStore::LOCALE)
    {
    }
}
