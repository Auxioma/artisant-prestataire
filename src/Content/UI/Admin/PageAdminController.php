<?php

declare(strict_types=1);

namespace App\Content\UI\Admin;

use App\Content\Application\Faq\FaqQuery;
use App\Content\Application\Media\MediaList;
use App\Content\Application\Media\MediaView;
use App\Content\Application\Page\PageBlockType;
use App\Content\Application\Page\PageCommand;
use App\Content\Application\Page\PageQuery;
use App\Content\Application\Page\PageRejected;
use App\Content\Application\Page\PageView;
use App\Content\UI\Http\PageController;
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
 * BO10.2 « Pages » (/admin/cms/pages) : pages à blocs réutilisables (texte, image, CTA, cartes, FAQ, carrousel de
 * professionnels, bannière), brouillon / publié / programmé, méta SEO, aperçu. Pas de maquette dédiée : P-130.
 */
#[Route('/admin/cms/pages')]
final class PageAdminController extends AbstractController
{
    private const string CSRF = 'admin_cms_pages';
    private const string UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    public function __construct(
        private readonly CommandBus $commands,
        private readonly QueryBus $queries,
        private readonly CurrentAdministrator $admin,
        private readonly IdGenerator $ids,
        private readonly Clock $clock,
    ) {
    }

    #[Route('', name: 'admin_cms_pages', methods: ['GET'])]
    #[IsGranted('admin.cms.view')]
    public function index(): Response
    {
        $pages = $this->queries->ask(new PageQuery());

        return $this->render('admin/cms/pages.html.twig', [
            'pages' => \is_array($pages) ? $pages : [],
            'now' => $this->clock->now(),
            'csrf_id' => self::CSRF,
        ]);
    }

    #[Route('', name: 'admin_cms_page_create', methods: ['POST'])]
    #[IsGranted('admin.cms.edit')]
    public function create(Request $request): Response
    {
        $this->assertCsrf($request);
        $id = $this->ids->next();
        try {
            $this->commands->dispatch(new PageCommand('create', $id, $this->admin->id(), ['title' => $request->request->getString('title'), 'slug' => $request->request->getString('slug')]));
        } catch (PageRejected $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_cms_pages', [], Response::HTTP_SEE_OTHER);
        }

        return $this->redirectToRoute('admin_cms_page', ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}', name: 'admin_cms_page', requirements: ['id' => self::UUID], methods: ['GET'])]
    #[IsGranted('admin.cms.view')]
    public function edit(string $id): Response
    {
        $page = $this->page($id);
        $media = $this->queries->ask(new MediaList());
        $topics = $this->queries->ask(FaqQuery::topics());

        return $this->render('admin/cms/page_edit.html.twig', [
            'page' => $page,
            'now' => $this->clock->now(),
            'types' => PageBlockType::cases(),
            'type_map' => array_combine(array_map(static fn (PageBlockType $t): string => $t->value, PageBlockType::cases()), PageBlockType::cases()),
            'media' => \is_array($media) ? array_values(array_filter($media, static fn (mixed $m): bool => $m instanceof MediaView && $m->ready())) : [],
            'topics' => \is_array($topics) ? $topics : [],
            'csrf_id' => self::CSRF,
        ]);
    }

    #[Route('/{id}', name: 'admin_cms_page_action', requirements: ['id' => self::UUID], methods: ['POST'])]
    #[IsGranted('admin.cms.edit')]
    public function action(Request $request, string $id): Response
    {
        $this->assertCsrf($request);
        $action = $request->request->getString('action');
        if (!\in_array($action, PageCommand::ACTIONS, true) || 'create' === $action) {
            throw new NotFoundHttpException();
        }
        /** @var array<string, mixed> $data */
        $data = $request->request->all('data');
        if ('meta' === $action) {
            $data['noindex'] = $request->request->getBoolean('noindex');
        }
        $block = $request->request->getString('block');
        try {
            $this->commands->dispatch(new PageCommand($action, $id, $this->admin->id(), $data, '' === $block ? null : $block, $request->request->getString('at')));
        } catch (PageRejected $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_cms_page', ['id' => $id, '_fragment' => '' === $block ? null : 'bloc-'.$block], Response::HTTP_SEE_OTHER);
        }
        $this->addFlash('success', match ($action) {
            'publish' => 'Page publiée.',
            'schedule' => 'Publication programmée.',
            'unpublish' => 'Page retirée du site.',
            'delete' => 'Page supprimée.',
            default => 'Modifications enregistrées dans le brouillon.',
        });
        if ('delete' === $action) {
            return $this->redirectToRoute('admin_cms_pages', [], Response::HTTP_SEE_OTHER);
        }

        return $this->redirectToRoute('admin_cms_page', ['id' => $id, '_fragment' => '' === $block ? null : 'bloc-'.$block], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/apercu', name: 'admin_cms_page_preview', requirements: ['id' => self::UUID], methods: ['GET'])]
    #[IsGranted('admin.cms.view')]
    public function preview(string $id, PageController $pages): Response
    {
        $page = $this->page($id);
        $response = $pages->renderPage($page, $page->draft, true);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }

    private function page(string $id): PageView
    {
        $page = $this->queries->ask(new PageQuery(id: $id));

        return $page instanceof PageView ? $page : throw new NotFoundHttpException();
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException();
        }
    }
}
