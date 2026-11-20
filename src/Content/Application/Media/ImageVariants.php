<?php

declare(strict_types=1);

namespace App\Content\Application\Media;

/** Conversion d'une image en variantes redimensionnées (WebP, AVIF) dans des fichiers temporaires. */
interface ImageVariants
{
    /**
     * @param resource     $source
     * @param list<int>    $widths  largeurs cibles (une variante plus large que l'original n'est pas produite, sauf la plus petite)
     * @param list<string> $formats webp, avif
     *
     * @return array{width: int, height: int, files: list<array{format: string, width: int, path: string}>}
     */
    public function generate($source, array $widths, array $formats): array;
}
