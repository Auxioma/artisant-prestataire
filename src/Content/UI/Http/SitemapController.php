<?php

declare(strict_types=1);

namespace App\Content\UI\Http;

use App\Catalog\Application\Query\CategorySummary;
use App\Catalog\Application\Query\JobIndexEntries;
use App\Catalog\Application\Query\JobIndexEntry;
use App\Catalog\Application\Query\MainCategories;
use App\Content\Application\Blog\BlogCategory;
use App\Content\Application\Blog\BlogPage;
use App\Content\Application\Blog\BlogQuery;
use App\Content\Application\Faq\FaqQuery;
use App\Content\Application\Faq\FaqQuestion;
use App\Content\Application\Faq\FaqTopic;
use App\Content\Application\Page\PageQuery;
use App\Content\Application\Page\PageView;
use App\Search\Application\Directory\CountArtisans;
use App\Search\Application\Directory\SitemapArtisans;
use App\Search\Application\Directory\SitemapLocalPages;
use App\Search\UI\Twig\ArtisanUrlExtension;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Legal\PublishedLegalDocuments;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * BO12.3 / §7 — sitemaps XML segmentés générés automatiquement (pages, métiers, villes, artisans, blog P15), au
 * plus SITEMAP_MAX_URLS adresses par fichier (< 50 000), et robots.txt. Seules les pages indexables y figurent :
 * pages ville selon RG-023, aucune page filtrée. Mis en cache CACHE_SECONDS.
 */
final readonly class SitemapController
{
    private const int SITEMAP_MAX_URLS = 45_000;
    private const int CACHE_SECONDS = 3600;
    private const array SEGMENTS = ['pages', 'metiers', 'villes', 'artisans', 'blog'];
    private const array DISALLOW = ['/admin/', '/api/', '/conditions/', '/inscription/', '/connexion', '/mot-de-passe', '/verification-email/', '/accueil/professionnels', '/media/'];
    private const array STATIC_ROUTES = ['public_home', 'catalog_categories', 'search_directory', 'content_how_it_works', 'content_pricing', 'content_faq', 'content_contact', 'content_sitemap', 'content_blog'];

    public function __construct(
        private QueryBus $queries,
        private SettingsProvider $settings,
        private UrlGeneratorInterface $urls,
        #[Autowire(service: 'cache.sitemap')]
        private CacheInterface $cache,
        #[Autowire('%env(bool:SEO_ALLOW_INDEXING)%')]
        private bool $indexing,
    ) {
    }

    #[Route('/robots.txt', name: 'content_robots', methods: ['GET'])]
    public function robots(): Response
    {
        $lines = ['User-agent: *'];
        if (!$this->indexing) {
            $lines[] = 'Disallow: /'; // préproduction, recette : rien n'est indexé
        } else {
            foreach (self::DISALLOW as $path) {
                $lines[] = 'Disallow: '.$path;
            }
            foreach ($this->settings->stringList(SettingKey::SeoRobotsExtra) as $line) {
                $lines[] = str_replace(["\r", "\n"], '', $line);
            }
            $lines[] = '';
            $lines[] = 'Sitemap: '.$this->urls->generate('content_sitemap_index', [], UrlGeneratorInterface::ABSOLUTE_URL);
        }

        return new Response(implode("\n", $lines)."\n", Response::HTTP_OK, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'public, max-age='.self::CACHE_SECONDS]);
    }

    #[Route('/sitemap.xml', name: 'content_sitemap_index', methods: ['GET'])]
    public function index(): Response
    {
        $xml = ['<?xml version="1.0" encoding="UTF-8"?>', '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'];
        foreach (self::SEGMENTS as $segment) {
            $pages = max(1, (int) ceil(\count($this->entries($segment)) / self::SITEMAP_MAX_URLS));
            for ($page = 1; $page <= $pages; ++$page) {
                $xml[] = '  <sitemap><loc>'.self::escape($this->urls->generate('content_sitemap_segment', ['segment' => $segment, 'page' => $page], UrlGeneratorInterface::ABSOLUTE_URL)).'</loc></sitemap>';
            }
        }
        $xml[] = '</sitemapindex>';

        return self::xml($xml);
    }

    #[Route('/sitemaps/{segment}-{page}.xml', name: 'content_sitemap_segment', requirements: ['segment' => 'pages|metiers|villes|artisans|blog', 'page' => '[1-9][0-9]{0,2}'], methods: ['GET'])]
    public function segment(string $segment, int $page): Response
    {
        $chunk = \array_slice($this->entries($segment), ($page - 1) * self::SITEMAP_MAX_URLS, self::SITEMAP_MAX_URLS);
        if ([] === $chunk && 1 !== $page) {
            throw new NotFoundHttpException();
        }
        $xml = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'];
        foreach ($chunk as [$loc, $lastmod]) {
            $xml[] = '  <url><loc>'.self::escape($loc).'</loc>'.(null === $lastmod ? '' : '<lastmod>'.$lastmod.'</lastmod>').'</url>';
        }
        $xml[] = '</urlset>';

        return self::xml($xml);
    }

    /**
     * BO12.2 — chemins indexables connus (suggestions de redirection du rapport des 404).
     *
     * @return list<string>
     */
    public function knownPaths(): array
    {
        $paths = [];
        foreach (self::SEGMENTS as $segment) {
            foreach ($this->entries($segment) as [$loc]) {
                $path = parse_url($loc, \PHP_URL_PATH);
                if (\is_string($path)) {
                    $paths[] = $path;
                }
            }
        }

        return $paths;
    }

    /**
     * BO12.3 — nombre d'adresses par segment (fichiers pages.xml, metiers.xml…).
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = [];
        foreach (self::SEGMENTS as $segment) {
            $counts[$segment] = \count($this->entries($segment));
        }

        return $counts;
    }

    /** BO12.3 « Régénérer » : vide le cache, les sitemaps sont recalculés à la prochaine lecture. */
    public function refresh(): void
    {
        foreach (self::SEGMENTS as $segment) {
            $this->cache->delete('sitemap.'.$segment);
        }
    }

    /** @return list<array{0: string, 1: ?string}> adresse absolue, date de dernière modification (Y-m-d) */
    private function entries(string $segment): array
    {
        /** @var list<array{0: string, 1: ?string}> $entries */
        $entries = $this->cache->get('sitemap.'.$segment, function (ItemInterface $item) use ($segment): array {
            $item->expiresAfter(self::CACHE_SECONDS);

            return match ($segment) {
                'pages' => $this->pages(),
                'metiers' => $this->catalog(),
                'villes' => $this->local(),
                'blog' => $this->blog(),
                default => $this->artisans(),
            };
        });

        return $entries;
    }

    /** @return list<array{0: string, 1: ?string}> */
    private function pages(): array
    {
        $entries = array_map(fn (string $route): array => [$this->url($route), null], self::STATIC_ROUTES);
        $total = $this->queries->ask(new CountArtisans());
        $pages = (int) ceil((\is_int($total) ? $total : 0) / max(1, $this->settings->int(SettingKey::SearchPageSize)));
        for ($n = 2; $n <= $pages; ++$n) {
            $entries[] = [$this->url('search_directory_page', ['page' => $n]), null]; // pagination indexable (§7)
        }
        $legal = $this->queries->ask(new PublishedLegalDocuments());
        foreach (\is_array($legal) ? $legal : [] as $slug => $date) {
            if (\is_string($slug) && $date instanceof \DateTimeImmutable) {
                $entries[] = [$this->url('identity_legal_document', ['slug' => $slug]), $date->format('Y-m-d')];
            }
        }
        // BO10.2 — pages éditoriales publiées et indexables.
        $now = new \DateTimeImmutable();
        $cmsPages = $this->queries->ask(new PageQuery());
        foreach (\is_array($cmsPages) ? $cmsPages : [] as $page) {
            if ($page instanceof PageView && !$page->noindex && 'published' === $page->status($now)) {
                $entries[] = [$this->url('content_page', ['slug' => $page->slug]), $page->updatedAt->format('Y-m-d')];
            }
        }
        $topics = $this->queries->ask(FaqQuery::topics());
        foreach (\is_array($topics) ? $topics : [] as $topic) {
            if (!$topic instanceof FaqTopic) {
                continue;
            }
            $entries[] = [$this->url('content_faq_topic', ['topic' => $topic->slug]), null];
            $questions = $this->queries->ask(FaqQuery::topic($topic->slug));
            foreach (\is_array($questions) ? $questions : [] as $q) {
                if ($q instanceof FaqQuestion) {
                    $entries[] = [$this->url('content_faq_question', ['topic' => $q->topicSlug, 'question' => $q->slug]), $q->updatedAt->format('Y-m-d')];
                }
            }
        }

        return $entries;
    }

    /** @return list<array{0: string, 1: ?string}> P15 : catégories ayant un article publié, articles publiés (pagination incluse) */
    private function blog(): array
    {
        $entries = [];
        $categories = $this->queries->ask(BlogQuery::categories());
        foreach (\is_array($categories) ? $categories : [] as $category) {
            if ($category instanceof BlogCategory && $category->publishedCount > 0) {
                $entries[] = [$this->url('content_blog_category', ['slug' => $category->slug]), null];
            }
        }
        $first = $this->queries->ask(BlogQuery::published(null, 1, BlogController::PER_PAGE));
        $pages = $first instanceof BlogPage ? $first->pages() : 1;
        for ($page = 1; $page <= $pages; ++$page) {
            $result = 1 === $page ? $first : $this->queries->ask(BlogQuery::published(null, $page, BlogController::PER_PAGE));
            if ($page > 1) {
                $entries[] = [$this->url('content_blog', ['page' => $page]), null]; // pagination indexable (§7)
            }
            foreach ($result instanceof BlogPage ? $result->articles : [] as $article) {
                $entries[] = [$this->url('content_blog_article', ['slug' => $article->slug]), $article->updatedAt->format('Y-m-d')];
            }
        }

        return $entries;
    }

    /** @return list<array{0: string, 1: ?string}> */
    private function catalog(): array
    {
        $entries = [];
        $categories = $this->queries->ask(new MainCategories(1));
        foreach (\is_array($categories) ? $categories : [] as $category) {
            if ($category instanceof CategorySummary) {
                $entries[] = [$this->url('catalog_category', ['slug' => $category->slug]), null];
            }
        }
        $jobs = $this->queries->ask(new JobIndexEntries());
        foreach (\is_array($jobs) ? $jobs : [] as $job) {
            if ($job instanceof JobIndexEntry && $job->searchable) {
                $entries[] = [$this->url('catalog_job', ['slug' => $job->slug]), null];
            }
        }

        return $entries;
    }

    /** @return list<array{0: string, 1: ?string}> */
    private function local(): array
    {
        $entries = [];
        $pages = $this->queries->ask(new SitemapLocalPages());
        foreach (\is_array($pages) ? $pages : [] as $page) {
            if (!\is_array($page)) {
                continue;
            }
            $city = \is_string($page['city'] ?? null) ? $page['city'] : null;
            $job = \is_string($page['job'] ?? null) ? $page['job'] : null;
            $department = \is_string($page['department'] ?? null) ? $page['department'] : null;
            $entries[] = match (true) {
                null !== $department => [$this->url('catalog_department', ['code' => $department]), null],
                null !== $city && null !== $job => [$this->url('catalog_job_city', ['job' => $job, 'city' => $city]), null],
                default => [$this->url('catalog_city', ['city' => (string) $city]), null],
            };
        }

        return $entries;
    }

    /** @return list<array{0: string, 1: ?string}> */
    private function artisans(): array
    {
        $artisans = $this->queries->ask(new SitemapArtisans());
        $entries = [];
        foreach (\is_array($artisans) ? $artisans : [] as $a) {
            if (\is_array($a) && \is_string($a['id'] ?? null) && \is_string($a['name'] ?? null)) {
                $entries[] = [
                    $this->url('public_artisan_profile', ['slug' => ArtisanUrlExtension::slug($a['name'], \is_string($a['commune'] ?? null) ? $a['commune'] : null), 'id' => $a['id']]),
                    \is_string($a['updated_at'] ?? null) ? substr($a['updated_at'], 0, 10) : null,
                ];
            }
        }

        return $entries;
    }

    /** @param array<string, string|int> $parameters */
    private function url(string $route, array $parameters = []): string
    {
        return $this->urls->generate($route, $parameters, UrlGeneratorInterface::ABSOLUTE_URL);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, \ENT_XML1 | \ENT_QUOTES, 'UTF-8');
    }

    /** @param list<string> $lines */
    private static function xml(array $lines): Response
    {
        return new Response(implode("\n", $lines)."\n", Response::HTTP_OK, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age='.self::CACHE_SECONDS, 'X-Robots-Tag' => 'noindex']);
    }
}
