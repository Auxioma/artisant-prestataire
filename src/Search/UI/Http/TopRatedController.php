<?php

declare(strict_types=1);

namespace App\Search\UI\Http;

use App\Catalog\Application\Geo\Query\Departments;
use App\Catalog\Application\Query\JobIndexEntries;
use App\Catalog\Application\Query\JobIndexEntry;
use App\Search\Application\Directory\TopRatedArtisans;
use App\Search\Application\Directory\TopRatedResult;
use App\Shared\Application\Bus\QueryBus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * P07 « Les mieux notés » (maquette Kerry « Les mieux notés — Desktop 1440 · V2 ») : filtres métier, zone
 * (département) et période (12 derniers mois par défaut, ou depuis toujours) ; classement RG-024 avec rang, note
 * bayésienne, nombre d'avis certifiés et dernier avis ; méthode affichée en clair. Formulaire GET, sans JavaScript.
 */
final class TopRatedController extends AbstractController
{
    public const array PERIODS = ['12-mois' => '12 derniers mois', 'toujours' => 'Depuis toujours'];
    private const string DEFAULT_PERIOD = '12-mois';

    public function __construct(private readonly QueryBus $queries)
    {
    }

    #[Route('/les-mieux-notes', name: 'search_top_rated', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $jobs = $this->queries->ask(new JobIndexEntries());
        $jobs = array_values(array_filter(\is_array($jobs) ? $jobs : [], static fn (mixed $j): bool => $j instanceof JobIndexEntry && $j->searchable));
        $departments = $this->queries->ask(new Departments());
        $departments = \is_array($departments) ? $departments : [];

        $slug = $request->query->getString('metier');
        $job = array_values(array_filter($jobs, static fn (JobIndexEntry $j): bool => $j->slug === $slug))[0] ?? null;
        $department = $request->query->getString('zone');
        $department = \array_key_exists($department, $departments) ? $department : null;
        $period = $request->query->getString('periode');
        $period = \array_key_exists($period, self::PERIODS) ? $period : self::DEFAULT_PERIOD;

        $result = $this->queries->ask(new TopRatedArtisans(null === $job ? [] : [$job->id], $department, 'toujours' === $period ? null : TopRatedArtisans::LAST_MONTHS));
        \assert($result instanceof TopRatedResult);
        $names = [];
        foreach ($jobs as $entry) {
            $names[$entry->id] = $entry->name;
        }
        $filters = array_filter(['metier' => $job?->slug, 'zone' => $department, 'periode' => self::DEFAULT_PERIOD === $period ? null : $period], static fn (?string $v): bool => null !== $v);

        $response = $this->render('search/top_rated.html.twig', [
            'result' => $result,
            'jobs' => $jobs,
            'job' => $job,
            'departments' => $departments,
            'department' => $department,
            'periods' => self::PERIODS,
            'period' => $period,
            'filters' => $filters,
            'job_names' => $names,
        ]);
        if ([] !== $filters) {
            $response->headers->set('X-Robots-Tag', 'noindex, follow');
        }

        return $response;
    }
}
