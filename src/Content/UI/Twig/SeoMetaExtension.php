<?php

declare(strict_types=1);

namespace App\Content\UI\Twig;

use App\Content\Application\Port\SeoMeta;
use App\Content\Application\Seo\SeoPageTypes;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * BO12.1 — title / description d'une page : surcharge de l'adresse courante (`tm_seo_override()`), sinon gabarit du
 * type de page (`tm_seo_meta('job_city', {metier: …})`), sinon le texte par défaut du gabarit Twig.
 */
final class SeoMetaExtension extends AbstractExtension implements ResetInterface
{
    /** @var array<string, array{title: ?string, description: ?string}>|null */
    private ?array $templates = null;
    /** @var array<string, array{path: string, title: ?string, description: ?string, noindex: bool}|null> */
    private array $overrides = [];

    public function __construct(private readonly SeoMeta $meta, private readonly RequestStack $requests)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('tm_seo_override', $this->override(...)),
            new TwigFunction('tm_seo_meta', $this->pageMeta(...)),
        ];
    }

    /** @return array{path: string, title: ?string, description: ?string, noindex: bool}|null */
    public function override(): ?array
    {
        $path = $this->requests->getMainRequest()?->getPathInfo();
        if (null === $path) {
            return null;
        }
        if (!\array_key_exists($path, $this->overrides)) {
            $this->overrides[$path] = $this->meta->override($path);
        }

        return $this->overrides[$path];
    }

    /**
     * @param array<string, string|int|null> $values
     *
     * @return array{title: ?string, description: ?string}
     */
    public function pageMeta(string $pageType, array $values): array
    {
        $this->templates ??= $this->meta->templates();
        $template = $this->templates[$pageType] ?? ['title' => null, 'description' => null];

        return ['title' => SeoPageTypes::render($template['title'], $values), 'description' => SeoPageTypes::render($template['description'], $values)];
    }

    public function reset(): void
    {
        $this->templates = null;
        $this->overrides = [];
    }
}
