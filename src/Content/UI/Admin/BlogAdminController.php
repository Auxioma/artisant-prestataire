<?php

declare(strict_types=1);

namespace App\Content\UI\Admin;

use App\Catalog\Application\Query\JobIndexEntries;
use App\Catalog\Application\Query\JobIndexEntry;
use App\Content\Application\Blog\BlogArticle;
use App\Content\Application\Blog\BlogCommand;
use App\Content\Application\Blog\BlogQuery;
use App\Content\Application\Blog\BlogRejected;
use App\Content\Application\Media\MediaList;
use App\Content\Application\Media\MediaView;
use App\Content\UI\Http\BlogController;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Security\CurrentAdministrator;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * BO10.3 — blog (pas de maquette dédiée : mise en page du CMS, P-130) : articles (création, édition, image et auteur
 * de la médiathèque, métiers liés, à la une, SEO, publication immédiate, programmée ou retrait, aperçu) et catégories.
 */
#[Route('/admin/cms/blog')]
final class BlogAdminController extends AbstractController
{
    private const string CSRF = 'admin_cms_blog';
    private const string UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    public function __construct(
        private readonly CommandBus $commands,
        private readonly QueryBus $queries,
        private readonly CurrentAdministrator $admin,
        private readonly IdGenerator $ids,
        private readonly Clock $clock,
    ) {
    }

    #[Route('', name: 'admin_cms_blog', methods: ['GET'])]
    #[IsGranted('admin.cms.view')]
    public function index(): Response
    {
        $articles = $this->queries->ask(BlogQuery::all());
        $categories = $this->queries->ask(BlogQuery::categories());

        return $this->render('admin/cms/blog.html.twig', [
            'articles' => \is_array($articles) ? $articles : [],
            'categories' => \is_array($categories) ? $categories : [],
            'now' => $this->clock->now(),
            'csrf_id' => self::CSRF,
        ]);
    }

    /** Création d'un article ou action sur une catégorie (création, renommage, suppression). */
    #[Route('', name: 'admin_cms_blog_post', methods: ['POST'])]
    #[IsGranted('admin.cms.edit')]
    public function post(Request $request): Response
    {
        $this->assertCsrf($request);
        $action = $request->request->getString('action');
        if (!\in_array($action, ['create', 'create_category', 'save_category', 'delete_category'], true)) {
            throw new NotFoundHttpException();
        }
        $id = 'create' === $action || 'create_category' === $action ? $this->ids->next() : $request->request->getString('id');
        /** @var array<string, mixed> $data */
        $data = $request->request->all('data');
        try {
            $this->commands->dispatch(new BlogCommand($action, $id, $this->admin->id(), $data));
        } catch (BlogRejected $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_cms_blog', [], Response::HTTP_SEE_OTHER);
        }
        if ('create' === $action) {
            return $this->redirectToRoute('admin_cms_blog_article', ['id' => $id], Response::HTTP_SEE_OTHER);
        }
        $this->addFlash('success', match ($action) {
            'create_category' => 'Catégorie ajoutée.',
            'delete_category' => 'Catégorie supprimée (ses articles restent, sans catégorie).',
            default => 'Catégorie enregistrée.',
        });

        return $this->redirectToRoute('admin_cms_blog', ['_fragment' => 'categories'], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}', name: 'admin_cms_blog_article', requirements: ['id' => self::UUID], methods: ['GET'])]
    #[IsGranted('admin.cms.view')]
    public function edit(string $id): Response
    {
        $media = $this->queries->ask(new MediaList());
        $categories = $this->queries->ask(BlogQuery::categories());

        return $this->render('admin/cms/blog_edit.html.twig', [
            'article' => $this->article($id),
            'categories' => \is_array($categories) ? $categories : [],
            'jobs' => $this->jobs(),
            'media' => \is_array($media) ? array_values(array_filter($media, static fn (mixed $m): bool => $m instanceof MediaView && $m->ready())) : [],
            'now' => $this->clock->now(),
            'csrf_id' => self::CSRF,
        ]);
    }

    #[Route('/{id}', name: 'admin_cms_blog_action', requirements: ['id' => self::UUID], methods: ['POST'])]
    #[IsGranted('admin.cms.edit')]
    public function action(Request $request, string $id): Response
    {
        $this->assertCsrf($request);
        $action = $request->request->getString('action');
        if (!\in_array($action, ['save', 'publish', 'schedule', 'unpublish', 'delete'], true)) {
            throw new NotFoundHttpException();
        }
        /** @var array<string, mixed> $data */
        $data = $request->request->all('data');
        if ('save' === $action) {
            $data['featured'] = $request->request->getBoolean('featured');
            // Métiers liés : seuls les métiers du référentiel sont retenus.
            $known = array_map(static fn (JobIndexEntry $j): string => $j->id, $this->jobs());
            $data['job_ids'] = array_values(array_intersect(array_filter($request->request->all('job_ids'), 'is_string'), $known));
        }
        try {
            $this->commands->dispatch(new BlogCommand($action, $id, $this->admin->id(), $data, $request->request->getString('at')));
        } catch (BlogRejected $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_cms_blog_article', ['id' => $id], Response::HTTP_SEE_OTHER);
        }
        $this->addFlash('success', match ($action) {
            'publish' => 'Article publié.',
            'schedule' => 'Publication programmée.',
            'unpublish' => 'Article retiré du site (brouillon).',
            'delete' => 'Article supprimé.',
            default => 'Article enregistré.',
        });

        return 'delete' === $action
            ? $this->redirectToRoute('admin_cms_blog', [], Response::HTTP_SEE_OTHER)
            : $this->redirectToRoute('admin_cms_blog_article', ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/apercu', name: 'admin_cms_blog_preview', requirements: ['id' => self::UUID], methods: ['GET'])]
    #[IsGranted('admin.cms.view')]
    public function preview(string $id, BlogController $blog): Response
    {
        $response = $blog->renderArticle($this->article($id), true);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }

    private function article(string $id): BlogArticle
    {
        $article = $this->queries->ask(BlogQuery::article($id));

        return $article instanceof BlogArticle ? $article : throw new NotFoundHttpException();
    }

    /** @return list<JobIndexEntry> métiers du référentiel, par catégorie puis nom */
    private function jobs(): array
    {
        $jobs = $this->queries->ask(new JobIndexEntries());
        $jobs = array_values(array_filter(\is_array($jobs) ? $jobs : [], static fn (mixed $j): bool => $j instanceof JobIndexEntry));
        usort($jobs, static fn (JobIndexEntry $a, JobIndexEntry $b): int => [$a->categoryName, $a->name] <=> [$b->categoryName, $b->name]);

        return $jobs;
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException();
        }
    }
}
