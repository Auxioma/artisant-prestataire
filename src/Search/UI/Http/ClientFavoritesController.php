<?php

declare(strict_types=1);

namespace App\Search\UI\Http;

use App\Catalog\Application\Query\JobIndexEntries;
use App\Catalog\Application\Query\JobIndexEntry;
use App\Search\Application\Favorite\ClientFavorites;
use App\Search\Application\Favorite\ClientFavoritesView;
use App\Search\Application\Favorite\ToggleFavorite;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Security\CurrentUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * C05 « Mes favoris » (/espace-client/favoris, Figma 230:12674) : artisans enregistrés (recherche, métier, ville, tri
 * par date d'ajout ou note), Contacter, Demander un devis, retirer ; artisan devenu inactif listé grisé. Ajout et retrait
 * depuis la fiche P10.
 */
#[Route('/espace-client/favoris')]
final class ClientFavoritesController extends AbstractController
{
    private const string CSRF = 'favorites';

    public function __construct(private readonly QueryBus $queries, private readonly CommandBus $commands, private readonly CurrentUser $user)
    {
    }

    #[Route('', name: 'client_favorites', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $q = $request->query;
        $view = $this->queries->ask(new ClientFavorites($this->userId(), $q->getString('q'), $q->getString('metier'), $q->getString('ville'), $q->getString('tri', 'ajout')));
        \assert($view instanceof ClientFavoritesView);
        $entries = [] === $view->jobIds() ? [] : $this->queries->ask(new JobIndexEntries($view->jobIds()));
        $jobs = [];
        foreach (\is_array($entries) ? $entries : [] as $entry) {
            if ($entry instanceof JobIndexEntry) {
                $jobs[$entry->id] = $entry->name;
            }
        }
        asort($jobs);

        return $this->render('client/favorites/index.html.twig', [
            'view' => $view,
            'jobs' => $jobs,
            'filters' => ['q' => $q->getString('q'), 'metier' => $q->getString('metier'), 'ville' => $q->getString('ville'), 'tri' => $q->getString('tri', 'ajout')],
            'sorts' => ClientFavorites::SORTS,
            'csrf_id' => self::CSRF,
        ]);
    }

    /** Ajout (`favori=1`) ou retrait d'un artisan ; retour à la page d'origine (fiche P10 ou liste). */
    #[Route('/{artisan}', name: 'client_favorite_toggle', requirements: ['artisan' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['POST'])]
    public function toggle(Request $request, string $artisan): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException();
        }
        $add = '1' === $request->request->getString('favori');
        $this->commands->dispatch(new ToggleFavorite($this->userId(), $artisan, $add));
        $this->addFlash('success', $add ? 'Ajouté à vos favoris.' : 'Retiré de vos favoris.');
        $back = $request->request->getString('retour');

        return $this->redirect(str_starts_with($back, '/') && !str_starts_with($back, '//') ? $back : $this->generateUrl('client_favorites'), Response::HTTP_SEE_OTHER);
    }

    private function userId(): string
    {
        return $this->user->id() ?? throw new AccessDeniedHttpException();
    }
}
