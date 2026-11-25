<?php

declare(strict_types=1);

namespace App\Content\UI\Http;

use App\Catalog\Application\Query\JobIndexEntries;
use App\Catalog\Application\Query\JobIndexEntry;
use App\Content\Application\Blog\BlogArticle;
use App\Content\Application\Blog\BlogCategory;
use App\Content\Application\Blog\BlogPage;
use App\Content\Application\Blog\BlogQuery;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Domain\Time\Clock;
use App\Shared\UI\Html\HeadingToc;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * P15 — blog (maquettes Floriane « Blog — liste » et « Blog — article » V2). Liste : pastilles de catégories, article
 * à la une, grille, pagination numérotée indexable. Article : bannière, fil d'Ariane, auteur et dates, sommaire automatique
 * (H2), image, encart « Trouvez un {métier} » après la première partie, métiers liés, partage, articles liés.
 */
final class BlogController extends AbstractController
{
    public const int PER_PAGE = 9;
    private const int RELATED = 3;
    private const string SLUG = '[a-z0-9]+(?:-[a-z0-9]+)*';

    public function __construct(private readonly QueryBus $queries, private readonly SettingsProvider $settings, private readonly Clock $clock)
    {
    }

    #[Route('/blog', name: 'content_blog', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->list($request, null);
    }

    #[Route('/blog/categorie/{slug}', name: 'content_blog_category', requirements: ['slug' => self::SLUG], methods: ['GET'])]
    public function category(Request $request, string $slug): Response
    {
        $category = $this->queries->ask(BlogQuery::category($slug));

        return $this->list($request, $category instanceof BlogCategory ? $category : throw new NotFoundHttpException());
    }

    #[Route('/blog/{slug}', name: 'content_blog_article', requirements: ['slug' => self::SLUG], methods: ['GET'], priority: -1)]
    public function article(string $slug): Response
    {
        $article = $this->queries->ask(BlogQuery::articleBySlug($slug));
        if (!$article instanceof BlogArticle || !$article->isVisible($this->clock->now())) {
            throw new NotFoundHttpException();
        }

        return $this->renderArticle($article, false);
    }

    /** Rendu d'un article, aussi utilisé par l'aperçu du BO (brouillon ou programmé, jamais indexé). */
    public function renderArticle(BlogArticle $article, bool $preview): Response
    {
        [$html, $toc] = HeadingToc::build($article->html);
        // Encart « Trouvez un {métier} » inséré avant la deuxième partie (maquette), sinon en fin d'article.
        $second = $this->nthHeading($html, 2);
        $parts = null === $second ? [$html, ''] : [substr($html, 0, $second), substr($html, $second)];

        $jobs = [];
        $entries = $this->queries->ask(new JobIndexEntries());
        foreach (\is_array($entries) ? $entries : [] as $entry) {
            if ($entry instanceof JobIndexEntry && $entry->searchable && \in_array($entry->id, $article->jobIds, true)) {
                $jobs[array_search($entry->id, $article->jobIds, true)] = $entry;
            }
        }
        ksort($jobs);
        $related = $this->queries->ask(BlogQuery::related($article->id, self::RELATED));

        return $this->render('content/blog/article.html.twig', [
            'article' => $article,
            'parts' => $parts,
            'toc' => $toc,
            'jobs' => array_values($jobs),
            'related' => \is_array($related) ? $related : [],
            'quotes_max' => $this->settings->int(SettingKey::QuotesMaxPerRequest),
            'preview' => $preview,
        ]);
    }

    private function list(Request $request, ?BlogCategory $category): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $result = $this->queries->ask(BlogQuery::published($category?->id, $page, self::PER_PAGE));
        \assert($result instanceof BlogPage);
        if ($page > $result->pages()) {
            throw new NotFoundHttpException('Page introuvable.');
        }
        $categories = $this->queries->ask(BlogQuery::categories());

        return $this->render('content/blog/list.html.twig', [
            'result' => $result,
            'category' => $category,
            'categories' => array_values(array_filter(\is_array($categories) ? $categories : [], static fn (mixed $c): bool => $c instanceof BlogCategory && $c->publishedCount > 0)),
            'now' => $this->clock->now(),
        ]);
    }

    /** Position (octets) du n-ième titre H2, ou null. */
    private function nthHeading(string $html, int $n): ?int
    {
        $offset = 0;
        for ($i = 1; $i <= $n; ++$i) {
            $position = stripos($html, '<h2', $offset);
            if (false === $position) {
                return null;
            }
            if ($i === $n) {
                return $position;
            }
            $offset = $position + 3;
        }

        return null;
    }
}
