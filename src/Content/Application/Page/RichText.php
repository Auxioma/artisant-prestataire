<?php

declare(strict_types=1);

namespace App\Content\Application\Page;

/** Assainissement du texte riche des blocs (liste blanche des contenus éditoriaux). */
interface RichText
{
    public function sanitize(string $html): string;
}
