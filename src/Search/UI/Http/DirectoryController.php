<?php

declare(strict_types=1);

namespace App\Search\UI\Http;

use App\Catalog\Application\Geo\Query\Departments;
use App\Catalog\Application\Query\CategorySummary;
use App\Catalog\Application\Query\JobIndexEntries;
use App\Catalog\Application\Query\JobIndexEntry;
use App\Catalog\Application\Query\MainCategories;
use App\Search\Application\Artisan\ArtisanHit;
use App\Search\Application\Directory\DirectoryArtisans;
use App\Search\Application\Directory\DirectoryResult;
use App\Search\Application\Directory\FeaturedArtisans;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * P06 — annuaire « Nos professionnels » : tous les artisans vérifiés par ordre alphabétique, filtres métier, département
 * et initiale, pagination numérotée. Pages non filtrées indexables avec des URLs propres (`/nos-professionnels/page/2`) ;
 * pages filtrées en noindex,follow (les pages métier × ville indexables sont les pages P05). Page d’entrée (maquette
 * Koubourath « Nos professionnels » V2, sans filtre ni pagination) : recherche, engagements, catégories, professionnels
 * à la une (RG-011) et encart pro, puis l’annuaire.
 */
final class DirectoryController extends AbstractController
{
    private const string LETTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ#';
    private const int FIRST_PAGE = 1;
    /** Maquette « Nos professionnels » V2 : 11 catégories + « Autres services », 3 professionnels à la une. */
    private const int CATEGORY_CARDS = 11;
    private const int FEATURED = 3;

    public function __construct(private readonly QueryBus $queries, private readonly SettingsProvider $settings)
    {
    }

    #[Route('/nos-professionnels', name: 'search_directory', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->directory($request, max(self::FIRST_PAGE, $request->query->getInt('page', self::FIRST_PAGE)));
    }

    #[Route('/nos-professionnels/page/{page}', name: 'search_directory_page', requirements: ['page' => '[1-9][0-9]{0,3}'], methods: ['GET'])]
    public function page(Request $request, int $page): Response
    {
        if (self::FIRST_PAGE === $page) {
            return $this->redirectToRoute('search_directory', status: Response::HTTP_MOVED_PERMANENTLY);
        }

        return $this->directory($request, $page);
    }

    private function directory(Request $request, int $page): Response
    {
        $jobs = $this->queries->ask(new JobIndexEntries());
        $jobs = array_values(array_filter(\is_array($jobs) ? $jobs : [], static fn (mixed $j): bool => $j instanceof JobIndexEntry && $j->searchable));
        $departments = $this->queries->ask(new Departments());
        $departments = \is_array($departments) ? $departments : [];

        $slug = $request->query->getString('metier');
        $job = array_values(array_filter($jobs, static fn (JobIndexEntry $j): bool => $j->slug === $slug))[0] ?? null;
        $department = $request->query->getString('departement');
        $department = \array_key_exists($department, $departments) ? $department : null;
        $letter = mb_strtoupper($request->query->getString('lettre'));
        $letter = 1 === mb_strlen($letter) && str_contains(self::LETTERS, $letter) ? $letter : null;
        $filters = array_filter(['metier' => $job?->slug, 'departement' => $department, 'lettre' => $letter], static fn (?string $v): bool => null !== $v);

        $result = $this->queries->ask(new DirectoryArtisans(null === $job ? [] : [$job->id], $department, $letter, page: $page));
        \assert($result instanceof DirectoryResult);
        if ($page > $result->pages()) {
            throw new NotFoundHttpException('Page introuvable.');
        }
        $names = [];
        foreach ($jobs as $entry) {
            $names[$entry->id] = $entry->name;
        }

        // Page d'entrée (maquette V2) : catégories et professionnels à la une, seulement sans filtre ni pagination.
        $landing = [] === $filters && self::FIRST_PAGE === $page;
        $categories = $landing ? $this->queries->ask(new MainCategories(1)) : [];
        $featured = $landing ? $this->queries->ask(new FeaturedArtisans([], self::FEATURED)) : [];

        $response = $this->render('search/directory.html.twig', [
            'landing' => $landing,
            'categories' => \array_slice(array_values(array_filter(\is_array($categories) ? $categories : [], static fn (mixed $c): bool => $c instanceof CategorySummary)), 0, self::CATEGORY_CARDS),
            'featured' => array_values(array_filter(\is_array($featured) ? $featured : [], static fn (mixed $h): bool => $h instanceof ArtisanHit)),
            'result' => $result,
            'jobs' => $jobs,
            'job' => $job,
            'department_options' => $this->departmentOptions($departments),
            'department' => $department,
            'letters' => str_split(self::LETTERS),
            'letter' => $letter,
            'filters' => $filters,
            'job_names' => $names,
            'free_credits' => $this->settings->int(SettingKey::CreditsFreeOnSignup),
        ]);
        if ([] !== $filters) {
            $response->headers->set('X-Robots-Tag', 'noindex, follow');
        }

        return $response;
    }

    /**
     * Options du filtre département. Opérateur + et non array_merge : les codes numériques (« 76 ») restent les clés.
     *
     * @param array<array-key, mixed> $departments
     *
     * @return array<array-key, string>
     */
    private function departmentOptions(array $departments): array
    {
        $options = ['' => 'Tous les départements'];
        foreach ($departments as $code => $name) {
            $options += [$code => $code.' — '.(\is_string($name) ? $name : '')];
        }

        return $options;
    }
}
