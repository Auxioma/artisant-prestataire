<?php

declare(strict_types=1);

namespace App\Catalog\UI\Admin;

use App\Catalog\Application\Command\CreateCategory;
use App\Catalog\Application\Command\CreateJob;
use App\Catalog\Application\Command\DeactivateJob;
use App\Catalog\Application\Command\UpdateCategory;
use App\Catalog\Application\Command\UpdateJob;
use App\Catalog\Application\Proposal\DecideJobProposal;
use App\Catalog\Application\Proposal\JobProposals;
use App\Catalog\Application\Query\CatalogTree;
use App\Catalog\Domain\Exception\CatalogRuleViolation;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Security\CurrentAdministrator;
use App\Shared\Domain\Exception\InvalidValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * BO06 « Référentiel métiers » : catégories et métiers (création, renommage, pictogramme, ordre, mise en avant,
 * activation / désactivation avec métier de remplacement) et propositions des clients (ajout, rattachement, refus).
 * Chaque modification publie les événements du catalogue : recherche, méga-menu, pages, filtres et listes à jour partout.
 */
#[Route('/admin/referentiel')]
final class CatalogAdminController extends AbstractController
{
    public const string TAB_PROPOSALS = 'propositions';
    private const string CSRF = 'admin_catalog';
    private const array ACTIONS = ['create_category', 'save_category', 'create_job', 'save_job', 'deactivate_job', 'activate_job', 'decide'];

    public function __construct(private readonly CommandBus $commands, private readonly QueryBus $queries, private readonly CurrentAdministrator $admin)
    {
    }

    #[Route('', name: 'admin_catalog', methods: ['GET'])]
    #[IsGranted('admin.catalog.view')]
    public function index(Request $request): Response
    {
        $tree = $this->queries->ask(new CatalogTree());
        $proposals = $this->queries->ask(new JobProposals());
        /** @var list<array{name: string, slug: string, active: bool, jobs: list<array{name: string, slug: string, active: bool}>}> $tree */
        $tree = \is_array($tree) ? $tree : [];
        $jobs = [];
        foreach ($tree as $category) {
            foreach ($category['jobs'] as $job) {
                if ($job['active'] && $category['active']) {
                    $jobs[$category['name']][] = $job;
                }
            }
        }

        return $this->render('admin/catalog/index.html.twig', [
            'tab' => self::TAB_PROPOSALS === $request->query->getString('onglet') ? self::TAB_PROPOSALS : 'categories',
            'tree' => $tree,
            'active_jobs' => $jobs,
            'proposals' => \is_array($proposals) ? $proposals : [],
            'pending_count' => $this->queries->ask(new JobProposals(true)),
            'icons' => self::ICONS,
            'csrf_id' => self::CSRF,
        ]);
    }

    #[Route('', name: 'admin_catalog_post', methods: ['POST'])]
    #[IsGranted('admin.catalog.edit')]
    public function post(Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Jeton CSRF invalide.');
        }
        $action = $request->request->getString('action');
        if (!\in_array($action, self::ACTIONS, true)) {
            throw new NotFoundHttpException();
        }
        $in = $request->request;
        $actor = $this->admin->id();
        $text = static fn (string $key): ?string => '' === trim($in->getString($key)) ? null : trim($in->getString($key));
        try {
            $command = match ($action) {
                'create_category' => new CreateCategory((string) $text('name'), $text('icon') ?? 'briefcase', featured: $in->getBoolean('featured'), actorId: $actor),
                'save_category' => new UpdateCategory($in->getString('slug'), $text('name'), $text('new_slug'), $text('icon'), $in->getBoolean('active'), $in->getBoolean('featured'), null === $text('position') ? null : $in->getInt('position'), $actor, $in->has('image_media_id') ? $in->getString('image_media_id') : null),
                'create_job' => new CreateJob($in->getString('category'), (string) $text('name'), synonyms: self::list($in->getString('synonyms')), actorId: $actor),
                'save_job' => new UpdateJob($in->getString('slug'), $text('name'), $text('new_slug'), $text('category'), self::list($in->getString('synonyms')), null === $text('position') ? null : $in->getInt('position'), $actor),
                'deactivate_job' => new DeactivateJob($in->getString('slug'), $text('replacement'), $actor),
                'activate_job' => new UpdateJob($in->getString('slug'), actorId: $actor, activate: true),
                default => new DecideJobProposal($in->getString('id'), (string) $actor, $in->getString('decision'), $text('name'), $text('category'), $text('replacement'), $text('note')),
            };
            $this->commands->dispatch($command);
        } catch (CatalogRuleViolation|InvalidValue $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->back($action, $in->getString('anchor'));
        }
        $this->addFlash('success', match ($action) {
            'create_category' => 'Catégorie ajoutée : elle apparaît sur la plateforme dès qu’elle contient un métier actif.',
            'create_job' => 'Métier ajouté : il est proposé dans la recherche, les filtres et le dépôt de demande.',
            'deactivate_job' => 'Métier désactivé : il n’est plus proposé (les références existantes pointent vers le remplaçant).',
            'activate_job' => 'Métier réactivé.',
            'decide' => match ($in->getString('decision')) {
                DecideJobProposal::APPROVE => 'Prestation ajoutée au référentiel : les demandes en attente sont publiées et les clients prévenus.',
                DecideJobProposal::MERGE => 'Proposition rattachée : les demandes en attente sont publiées sur le métier choisi et les clients prévenus.',
                default => 'Proposition refusée : les clients concernés sont prévenus avec le motif.',
            },
            default => 'Modifications enregistrées : elles sont visibles partout sur la plateforme.',
        });

        return $this->back($action, $in->getString('anchor'));
    }

    private function back(string $action, string $anchor): Response
    {
        $params = 'decide' === $action ? ['onglet' => self::TAB_PROPOSALS] : [];
        if (1 === preg_match('/^[a-z0-9-]{1,100}$/', $anchor)) {
            $params['_fragment'] = $anchor;
        }

        return $this->redirectToRoute('admin_catalog', $params, Response::HTTP_SEE_OTHER);
    }

    /** @return list<string> synonymes saisis séparés par des virgules */
    private static function list(string $value): array
    {
        return array_values(array_filter(array_map(trim(...), explode(',', $value)), static fn (string $s): bool => '' !== $s));
    }

    /** Pictogrammes du sprite proposés pour une catégorie. */
    private const array ICONS = ['wrench' => 'Clé', 'zap' => 'Éclair', 'flame' => 'Flamme', 'paint' => 'Pinceau', 'home' => 'Maison', 'leaf' => 'Feuille', 'briefcase' => 'Mallette', 'building' => 'Bâtiment', 'layers' => 'Couches', 'umbrella' => 'Parapluie', 'shield' => 'Bouclier', 'tag' => 'Étiquette', 'bulb' => 'Ampoule', 'users' => 'Personnes', 'settings' => 'Engrenage', 'camera' => 'Appareil photo'];
}
