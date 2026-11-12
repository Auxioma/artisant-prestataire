<?php

declare(strict_types=1);

namespace App\Content\UI\Admin;

use App\Content\Application\Home\DiscardHomeDraft;
use App\Content\Application\Home\HomeBlockSchema;
use App\Content\Application\Home\HomeEditorState;
use App\Content\Application\Home\HomeEditorView;
use App\Content\Application\Home\MoveHomeBlock;
use App\Content\Application\Home\PublishedHome;
use App\Content\Application\Home\PublishHome;
use App\Content\Application\Home\RestoreHomeVersion;
use App\Content\Application\Home\SaveHomeBlock;
use App\Content\Application\Home\ToggleHomeBlock;
use App\Content\Domain\Home\HomeBlockType;
use App\Content\Domain\Home\HomeContentViolation;
use App\Content\UI\Http\HomePageData;
use App\Shared\Application\Bus\Command;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\Query;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Security\CurrentAdministrator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * BO10.1 (Figma 186:4326) — CMS de la page d'accueil : activer, réordonner et éditer les blocs sur un brouillon, aperçu
 * desktop / mobile, publication immédiate ou programmée, historique et restauration de version.
 */
#[Route('/admin/cms')]
final class HomeCmsController extends AbstractController
{
    private const string UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
    private const string TIMEZONE = 'Europe/Paris';

    public function __construct(private readonly CommandBus $commands, private readonly QueryBus $queries, private readonly CurrentAdministrator $admin)
    {
    }

    #[Route('', name: 'admin_cms', methods: ['GET'])]
    #[IsGranted('admin.cms.view')]
    public function index(Request $request): Response
    {
        $state = $this->state();
        $selected = HomeBlockType::tryFrom($request->query->getString('bloc'));
        $block = null;
        foreach ($state->blocks as $candidate) {
            if (null !== $selected && $candidate['type'] === $selected->value && !$selected->isGlobal()) {
                $block = $candidate;
            }
        }

        return $this->render('admin/cms/home.html.twig', [
            'state' => $state,
            'types' => array_map(static fn (array $b): ?HomeBlockType => HomeBlockType::tryFrom($b['type']), $state->blocks),
            'selected' => null === $block ? null : $selected,
            'block' => $block,
            'fields' => null === $block || null === $selected ? [] : HomeBlockSchema::fields($selected),
            'variables' => HomeBlockSchema::VARIABLES,
        ]);
    }

    #[Route('/accueil/blocs/{type}', name: 'admin_cms_block_save', methods: ['POST'])]
    #[IsGranted('admin.cms.edit')]
    public function save(Request $request, string $type): Response
    {
        $this->assertCsrf($request, 'admin_cms_block_'.$type);
        $input = $request->request->all('settings');

        return $this->run(new SaveHomeBlock($this->adminId(), $type, $request->request->getBoolean('enabled'), $input), 'Bloc enregistré dans le brouillon.', ['bloc' => $type]);
    }

    #[Route('/accueil/blocs/{type}/activation', name: 'admin_cms_block_toggle', methods: ['POST'])]
    #[IsGranted('admin.cms.edit')]
    public function toggle(Request $request, string $type): Response
    {
        $this->assertCsrf($request, 'admin_cms_toggle_'.$type);

        return $this->run(new ToggleHomeBlock($this->adminId(), $type, $request->request->getBoolean('enabled')), null, []);
    }

    #[Route('/accueil/blocs/{type}/deplacer', name: 'admin_cms_block_move', methods: ['POST'])]
    #[IsGranted('admin.cms.edit')]
    public function move(Request $request, string $type): Response
    {
        $this->assertCsrf($request, 'admin_cms_move_'.$type);

        return $this->run(new MoveHomeBlock($this->adminId(), $type, $request->request->getInt('offset')), null, []);
    }

    #[Route('/accueil/publier', name: 'admin_cms_publish', methods: ['POST'])]
    #[IsGranted('admin.cms.edit')]
    public function publish(Request $request): Response
    {
        $this->assertCsrf($request, 'admin_cms_publish');
        $at = null;
        $raw = $request->request->getString('publish_at');
        if ('' !== $raw) {
            $at = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $raw, new \DateTimeZone(self::TIMEZONE));
            if (false === $at) {
                $this->addFlash('error', 'Date de publication invalide.');

                return $this->redirectToRoute('admin_cms', status: Response::HTTP_SEE_OTHER);
            }
        }

        return $this->run(new PublishHome($this->adminId(), $at), null === $at ? 'Page d’accueil publiée.' : 'Publication programmée le '.$at->format('d/m/Y à H:i').'.', []);
    }

    #[Route('/accueil/brouillon/abandon', name: 'admin_cms_discard', methods: ['POST'])]
    #[IsGranted('admin.cms.edit')]
    public function discard(Request $request): Response
    {
        $this->assertCsrf($request, 'admin_cms_discard');

        return $this->run(new DiscardHomeDraft($this->adminId()), 'Brouillon abandonné.', []);
    }

    #[Route('/accueil/historique', name: 'admin_cms_history', methods: ['GET'])]
    #[IsGranted('admin.cms.view')]
    public function history(): Response
    {
        return $this->render('admin/cms/history.html.twig', ['state' => $this->state()]);
    }

    #[Route('/accueil/historique/{id}/restaurer', name: 'admin_cms_restore', requirements: ['id' => self::UUID], methods: ['POST'])]
    #[IsGranted('admin.cms.edit')]
    public function restore(Request $request, string $id): Response
    {
        $this->assertCsrf($request, 'admin_cms_restore_'.$id);

        return $this->run(new RestoreHomeVersion($this->adminId(), $id), 'Version restaurée dans le brouillon : vérifiez-la puis publiez.', []);
    }

    #[Route('/accueil/apercu', name: 'admin_cms_preview', methods: ['GET'])]
    #[IsGranted('admin.cms.view')]
    public function preview(Request $request): Response
    {
        return $this->render('admin/cms/preview.html.twig', ['version' => $this->previewVersion($request)]);
    }

    /** Rendu de la page d'accueil pour l'aperçu (cadre de même origine) : brouillon ou version donnée, jamais indexé. */
    #[Route('/accueil/apercu/rendu', name: 'admin_cms_preview_render', methods: ['GET'])]
    #[IsGranted('admin.cms.view')]
    public function previewRender(Request $request, HomePageData $data): Response
    {
        $blocks = $this->queries->ask(new PublishedHome($this->previewVersion($request)));
        $response = $this->render('content/home.html.twig', $data->build(HomePageData::blocks($blocks), 'guest') + ['preview' => true, 'header_variant' => 'visitor']);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function previewVersion(Request $request): ?string
    {
        $requested = $request->query->getString('version');
        if (1 === preg_match('/^'.self::UUID.'$/', $requested)) {
            return $requested;
        }

        return $this->state()->draft?->id;
    }

    /** @param array<string, string> $params */
    private function run(Command $command, ?string $success, array $params): Response
    {
        try {
            $this->commands->dispatch($command);
            if (null !== $success) {
                $this->addFlash('success', $success);
            }
        } catch (HomeContentViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_cms', $params, Response::HTTP_SEE_OTHER);
    }

    private function state(): HomeEditorView
    {
        $state = $this->ask(new HomeEditorState());

        return $state instanceof HomeEditorView ? $state : throw new NotFoundHttpException();
    }

    private function ask(Query $query): mixed
    {
        return $this->queries->ask($query);
    }

    private function adminId(): string
    {
        return $this->admin->id() ?? throw new AccessDeniedHttpException();
    }

    private function assertCsrf(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Jeton CSRF invalide.');
        }
    }
}
