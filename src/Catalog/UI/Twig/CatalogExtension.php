<?php

declare(strict_types=1);

namespace App\Catalog\UI\Twig;

use App\Catalog\Application\Query\CategorySummary;
use App\Catalog\Application\Query\MainCategories;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * tm_main_categories() : méga-menu et menu mobile (P01.1) — catégories actives, 3 à 5 métiers chacune
 * (catalog.megamenu_jobs), ordre du BO06 ; résultat mis en cache, invalidé à chaque modification du référentiel.
 */
final class CatalogExtension extends AbstractExtension
{
    private const int CACHE_TTL_SECONDS = 3600;

    public function __construct(
        private readonly QueryBus $queries,
        private readonly SettingsProvider $settings,
        private readonly RouterInterface $router,
        private readonly CacheInterface $catalogCache,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('tm_main_categories', $this->mainCategories(...))];
    }

    /**
     * @return list<array{name: string, href: string, icon: string, jobs: list<array{name: string, href: string}>}>
     */
    public function mainCategories(): array
    {
        $summaries = $this->catalogCache->get(MainCategories::MENU_CACHE_KEY, function (ItemInterface $item): array {
            $item->expiresAfter(self::CACHE_TTL_SECONDS);
            $result = $this->queries->ask(new MainCategories($this->settings->int(SettingKey::CatalogMegaMenuJobs)));

            return \is_array($result) ? $result : [];
        });

        $categories = [];
        foreach ($summaries as $summary) {
            if (!$summary instanceof CategorySummary) {
                continue;
            }
            $categories[] = [
                'name' => $summary->name,
                'href' => $this->path('catalog_category', $summary->slug),
                'icon' => $summary->icon,
                'jobs' => array_map(fn (array $job): array => ['name' => $job['name'], 'href' => $this->path('catalog_job', $job['slug'])], $summary->jobs),
            ];
        }

        return $categories;
    }

    /** Route de la page si elle existe, sinon « # » (pages livrées au lot 3 — P-32). */
    private function path(string $route, string $slug): string
    {
        return null !== $this->router->getRouteCollection()->get($route) ? $this->router->generate($route, ['slug' => $slug]) : '#';
    }
}
