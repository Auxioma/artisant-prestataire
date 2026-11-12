<?php

declare(strict_types=1);

namespace App\ArtisanProfile\UI\Admin;

use App\ArtisanProfile\Application\Admin\ArtisanList;
use App\ArtisanProfile\Application\Admin\ArtisanListPage;
use App\ArtisanProfile\Application\Verification\DossierView;
use App\ArtisanProfile\Application\Verification\ReinstateArtisan;
use App\ArtisanProfile\Application\Verification\SuspendArtisan;
use App\ArtisanProfile\Application\Verification\VerificationDossier;
use App\ArtisanProfile\Domain\Profile\ArtisanRuleViolation;
use App\Shared\Application\Billing\RemainingCredits;
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
 * BO03.1 (liste des comptes pros) et BO03.3 (fiche artisan) : statut de vérification, métiers, département, crédits
 * restants, complétude ; suspension motivée / réactivation (RG-043, E-26). Forfait, note, devis, avis, gestes
 * commerciaux et connexion « en tant que » arrivent avec leurs lots.
 */
#[Route('/admin/artisans')]
final class ArtisansController extends AbstractController
{
    private const int PER_PAGE = 25;

    public function __construct(private readonly CommandBus $commands, private readonly QueryBus $queries, private readonly CurrentAdministrator $admin)
    {
    }

    #[Route('', name: 'admin_artisans', methods: ['GET'])]
    #[IsGranted('admin.artisans.view')]
    public function index(Request $request): Response
    {
        $filters = ['q' => mb_substr(trim($request->query->getString('q')), 0, 100), 'statut' => $request->query->getString('statut')];
        $page = $this->queries->ask(new ArtisanList($filters['q'], $filters['statut'] ?: null, max(1, $request->query->getInt('page', 1)), self::PER_PAGE));
        \assert($page instanceof ArtisanListPage);

        return $this->render('admin/artisans/list.html.twig', ['page' => $page, 'filters' => $filters]);
    }

    #[Route('/{id}', name: 'admin_artisans_show', requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['GET'])]
    #[IsGranted('admin.artisans.view')]
    public function show(string $id): Response
    {
        $dossier = $this->queries->ask(new VerificationDossier($id));
        if (!$dossier instanceof DossierView) {
            throw $this->createNotFoundException();
        }
        $credits = $this->queries->ask(new RemainingCredits([$dossier->userId]));

        return $this->render('admin/artisans/show.html.twig', [
            'dossier' => $dossier,
            'credits' => \is_array($credits) && \is_int($credits[$dossier->userId] ?? null) ? $credits[$dossier->userId] : null,
        ]);
    }

    #[Route('/{id}/statut', name: 'admin_artisans_status', requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['POST'])]
    #[IsGranted('admin.artisans.edit')]
    public function status(Request $request, string $id): Response
    {
        if (!$this->isCsrfTokenValid('admin_artisan_status_'.$id, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Jeton CSRF invalide.');
        }
        $admin = $this->admin->id() ?? throw new AccessDeniedHttpException();
        try {
            if ('suspend' === $request->request->getString('action')) {
                $this->commands->dispatch(new SuspendArtisan($id, $admin, $request->request->getString('reason')));
                $this->addFlash('success', 'Compte professionnel suspendu : retiré de la recherche et de la diffusion, artisan prévenu (E-26).');
            } else {
                $this->commands->dispatch(new ReinstateArtisan($id, $admin));
                $this->addFlash('success', 'Suspension levée : artisan prévenu (E-26).');
            }
        } catch (ArtisanRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_artisans_show', ['id' => $id], Response::HTTP_SEE_OTHER);
    }
}
