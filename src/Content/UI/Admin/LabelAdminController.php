<?php

declare(strict_types=1);

namespace App\Content\UI\Admin;

use App\Content\Application\Label\LabelCommand;
use App\Content\Application\Label\LabelQuery;
use App\Content\Application\Label\LabelRejected;
use App\Content\Application\Label\UiLabel;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Security\CurrentAdministrator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * BO10.6 « Libellés » (/admin/cms/libelles) : dictionnaire des textes d'interface par rubrique, recherche, texte modifié
 * ou retour au texte d'origine. Langue : français (préparation multilingue). P-133.
 */
#[Route('/admin/cms/libelles')]
final class LabelAdminController extends AbstractController
{
    private const string CSRF = 'admin_cms_labels';
    private const array GROUPS = ['header' => 'En-tête', 'footer' => 'Pied de page'];

    public function __construct(private readonly CommandBus $commands, private readonly QueryBus $queries, private readonly CurrentAdministrator $admin)
    {
    }

    #[Route('', name: 'admin_cms_labels', methods: ['GET'])]
    #[IsGranted('admin.cms.view')]
    public function index(Request $request): Response
    {
        $term = mb_strtolower(trim($request->query->getString('q')));
        $labels = $this->queries->ask(new LabelQuery());
        $groups = [];
        foreach (\is_array($labels) ? $labels : [] as $label) {
            if ($label instanceof UiLabel && ('' === $term || str_contains(mb_strtolower($label->key.' '.$label->value().' '.$label->defaultText), $term))) {
                $groups[$label->group()][] = $label;
            }
        }

        return $this->render('admin/cms/labels.html.twig', [
            'groups' => $groups,
            'group_names' => self::GROUPS,
            'term' => $request->query->getString('q'),
            'csrf_id' => self::CSRF,
        ]);
    }

    #[Route('', name: 'admin_cms_labels_save', methods: ['POST'])]
    #[IsGranted('admin.cms.edit')]
    public function save(Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException();
        }
        $key = $request->request->getString('key');
        try {
            $this->commands->dispatch(new LabelCommand($key, 'reset' === $request->request->getString('action') ? null : $request->request->getString('text'), $this->admin->id()));
            $this->addFlash('success', 'Libellé enregistré : visible immédiatement sur le site.');
        } catch (LabelRejected $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_cms_labels', ['q' => $request->request->getString('q') ?: null, '_fragment' => 'label-'.str_replace('.', '-', $key)], Response::HTTP_SEE_OTHER);
    }
}
