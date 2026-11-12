<?php

declare(strict_types=1);

namespace App\Content\Application\Home;

/**
 * @phpstan-import-type Block from HomeVersion
 */
final readonly class HomeEditorView
{
    /**
     * @param list<Block>       $blocks  blocs de travail (brouillon, sinon version programmée ou en ligne, sinon contenu initial)
     * @param list<HomeVersion> $history
     */
    public function __construct(
        public array $blocks,
        public ?HomeVersion $draft,
        public ?HomeVersion $live,
        public ?HomeVersion $scheduled,
        public array $history,
    ) {
    }
}
