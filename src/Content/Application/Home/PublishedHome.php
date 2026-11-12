<?php

declare(strict_types=1);

namespace App\Content\Application\Home;

use App\Shared\Application\Bus\Query;

/**
 * P01 — blocs actifs de la version en ligne (contenu initial si rien n'est publié), ou d'une version donnée pour
 * l'aperçu du back-office. Réponse : list<Block> (blocs désactivés exclus).
 */
final readonly class PublishedHome implements Query
{
    public function __construct(public ?string $previewVersionId = null)
    {
    }
}
