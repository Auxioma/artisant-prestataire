<?php

declare(strict_types=1);

namespace App\Content\UI\Twig;

use App\Content\Application\Media\MediaList;
use App\Content\Application\Media\MediaView;
use App\Shared\Application\Bus\QueryBus;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** `tm_media(id)` : image de la médiathèque prête à afficher (composant `<twig:Media>`), null sinon. */
final class MediaExtension extends AbstractExtension
{
    /** @var array<string, ?MediaView> */
    private array $cache = [];

    public function __construct(private readonly QueryBus $queries)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('tm_media', $this->media(...))];
    }

    public function media(?string $id): ?MediaView
    {
        if (null === $id || '' === $id) {
            return null;
        }
        if (!\array_key_exists($id, $this->cache)) {
            $media = $this->queries->ask(new MediaList($id));
            $this->cache[$id] = $media instanceof MediaView && $media->ready() ? $media : null;
        }

        return $this->cache[$id];
    }
}
