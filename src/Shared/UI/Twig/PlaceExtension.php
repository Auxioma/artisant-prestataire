<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/** Préposition de lieu avec contraction de l'article : « à Harfleur », « au Havre », « aux Andelys ». */
final class PlaceExtension extends AbstractExtension
{
    private const array CONTRACTIONS = ['Le ' => 'au ', 'Les ' => 'aux '];
    private const string DEFAULT = 'à ';

    public function getFilters(): array
    {
        return [new TwigFilter('a_lieu', self::at(...))];
    }

    public static function at(string $place): string
    {
        foreach (self::CONTRACTIONS as $article => $contracted) {
            if (str_starts_with($place, $article)) {
                return $contracted.substr($place, \strlen($article));
            }
        }

        return self::DEFAULT.$place;
    }
}
