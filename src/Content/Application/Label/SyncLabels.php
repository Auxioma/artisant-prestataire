<?php

declare(strict_types=1);

namespace App\Content\Application\Label;

use App\Shared\Application\Bus\Command;

/** Enregistre les libellés relevés dans les gabarits (texte d'origine) ; les textes modifiés sont conservés. */
final readonly class SyncLabels implements Command
{
    /** @param array<string, string> $defaults clé → texte d'origine */
    public function __construct(public array $defaults, public string $locale = LabelStore::LOCALE)
    {
    }
}
