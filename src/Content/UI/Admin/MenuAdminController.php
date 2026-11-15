<?php

declare(strict_types=1);

namespace App\Content\UI\Admin;

use App\Content\Application\Menu\MenuCommand;
use App\Content\Application\Menu\MenuItem;
use App\Content\Application\Menu\MenuQuery;
use App\Content\Application\Menu\MenuRejected;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Security\CurrentAdministrator;
use App\Shared\Domain\Identifier\IdGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * BO10.5 « Menus » (/admin/cms/menus) : menu principal, colonnes du pied de page, menus « Mon compte » — libellé, lien,
 * nouvel onglet, visibilité par profil, actif, ordre. Le contenu du méga-menu vient du référentiel (BO06). P-132.
 */
#[Route('/admin/cms/menus')]
final class MenuAdminController extends AbstractController
{
    private const string CSRF = 'admin_cms_menus';

    public function __construct(private readonly CommandBus $commands, private readonly QueryBus $queries, private readonly CurrentAdministrator $admin, private readonly IdGenerator $ids)
    {
    }

    #[Route('', name: 'admin_cms_menus', methods: ['GET'])]
    #[IsGranted('admin.cms.view')]
    public function index(): Response
    {
        $items = $this->queries->ask(new MenuQuery());
        $menus = array_fill_keys(array_keys(MenuItem::MENUS), []);
        foreach (\is_array($items) ? $items : [] as $item) {
            if ($item instanceof MenuItem) {
                $menus[$item->menu][] = $item;
            }
        }

        return $this->render('admin/cms/menus.html.twig', [
            'menus' => $menus,
            'labels' => MenuItem::MENUS,
            'audiences' => MenuItem::AUDIENCES,
            'specials' => MenuItem::SPECIALS,
            'csrf_id' => self::CSRF,
        ]);
    }

    #[Route('', name: 'admin_cms_menus_action', methods: ['POST'])]
    #[IsGranted('admin.cms.edit')]
    public function action(Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException();
        }
        $action = $request->request->getString('action');
        if (!\in_array($action, MenuCommand::ACTIONS, true)) {
            throw new NotFoundHttpException();
        }
        /** @var array<string, mixed> $data */
        $data = $request->request->all('data');
        $data['new_tab'] = $request->request->getBoolean('new_tab');
        $data['active'] = $request->request->getBoolean('active');
        try {
            $this->commands->dispatch(new MenuCommand($action, $request->request->getString('id') ?: $this->ids->next(), $this->admin->id(), $data));
            $this->addFlash('success', 'Menus mis à jour.');
        } catch (MenuRejected $e) {
            $this->addFlash('error', $e->getMessage());
        }
        $menu = \is_string($data['menu'] ?? null) ? $data['menu'] : $request->request->getString('menu');

        return $this->redirectToRoute('admin_cms_menus', ['_fragment' => '' === $menu ? null : 'menu-'.$menu], Response::HTTP_SEE_OTHER);
    }
}
