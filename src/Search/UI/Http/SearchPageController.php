<?php

declare(strict_types=1);

namespace App\Search\UI\Http;

use App\Catalog\Application\Geo\Query\CommunesByInsee;
use App\Catalog\Application\Geo\Query\CommuneSummary;
use App\Catalog\Application\Geo\Query\FindCommunes;
use App\Catalog\Application\Query\JobBySlug;
use App\Catalog\Application\Query\JobIndexEntries;
use App\Catalog\Application\Query\JobIndexEntry;
use App\Search\Application\Artisan\ArtisanSearchResult;
use App\Search\Application\Artisan\SearchArtisans;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * P02 (Figma 95:395) — recherche et résultats : `/recherche?metier={slug}&lieu={insee}&rayon=30&note=4&tri=pertinence&page=1`.
 * Fonctionne sans JavaScript (formulaire GET, « Afficher plus » par lien conservant la position au retour) ; URL
 * partageable ; pages filtrées en noindex,follow (les pages métier × ville indexables sont les pages P05).
 */
final class SearchPageController extends AbstractController
{
    private const array MIN_RATINGS = ['3' => 3.0, '4' => 4.0, '4.5' => 4.5];
    private const array SORTS = [
        SearchArtisans::SORT_RELEVANCE => 'Pertinence',
        SearchArtisans::SORT_DISTANCE => 'Distance',
        SearchArtisans::SORT_RATING => 'Meilleures notes',
        SearchArtisans::SORT_REVIEWS => 'Nombre d’avis',
    ];
    private const int MAX_LABELS = 10;
    private const int MAX_PAGE = 50;

    public function __construct(private readonly QueryBus $queries, private readonly SettingsProvider $settings)
    {
    }

    #[Route('/recherche', name: 'public_search', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $radiusOptions = $this->settings->intList(SettingKey::SearchRadiusOptions);
        $radius = $request->query->getInt('rayon', $this->settings->int(SettingKey::SearchDefaultRadiusKm));
        if (!\in_array($radius, $radiusOptions, true)) {
            $radius = $this->settings->int(SettingKey::SearchDefaultRadiusKm);
        }

        $job = $this->job($request->query->getString('metier'));
        $categoryJobs = null === $job ? [] : $this->categoryJobs($job);
        $selectedSlugs = array_values(array_filter($request->query->all('metiers'), static fn (mixed $s): bool => \is_string($s)));
        $selectedJobs = array_values(array_filter($categoryJobs, static fn (JobIndexEntry $j): bool => \in_array($j->slug, $selectedSlugs, true)));
        if (null !== $job && [] === $selectedJobs) {
            $selectedJobs = [$job];
        }

        $place = $this->place($request->query->getString('lieu'), trim($request->query->getString('lieu_libelle')));
        $note = $request->query->getString('note');
        $labels = \array_slice(array_values(array_filter($request->query->all('labels'), static fn (mixed $l): bool => \is_string($l) && '' !== $l && mb_strlen($l) <= 80)), 0, self::MAX_LABELS);
        $sort = \array_key_exists($request->query->getString('tri'), self::SORTS) ? $request->query->getString('tri') : SearchArtisans::SORT_RELEVANCE;
        $page = max(1, min(self::MAX_PAGE, $request->query->getInt('page', 1)));

        $result = null;
        $jobNames = [];
        if (null !== $place) {
            $result = $this->queries->ask(new SearchArtisans(
                $place->insee,
                $radius,
                array_map(static fn (JobIndexEntry $j): string => $j->id, $selectedJobs),
                $sort,
                self::MIN_RATINGS[$note] ?? null,
                $labels,
                $page,
                cumulative: true,
            ));
            \assert($result instanceof ArtisanSearchResult);
            $jobNames = $this->jobNames($result);
        }

        $params = array_filter([
            'metier' => $job?->slug,
            'lieu' => $place?->insee,
            'rayon' => $radius,
            'metiers' => array_map(static fn (JobIndexEntry $j): string => $j->slug, $selectedJobs),
            'note' => isset(self::MIN_RATINGS[$note]) ? $note : null,
            'labels' => $labels,
            'tri' => SearchArtisans::SORT_RELEVANCE === $sort ? null : $sort,
        ], static fn (mixed $v): bool => null !== $v && [] !== $v);

        $response = $this->render('search/results.html.twig', [
            'job' => $job,
            'category_jobs' => $categoryJobs,
            'selected_jobs' => $selectedJobs,
            'place' => $place,
            'place_label' => null === $place ? trim($request->query->getString('lieu_libelle')) : self::label($place),
            'radius' => $radius,
            'radius_options' => $radiusOptions,
            'max_radius' => [] === $radiusOptions ? $radius : max($radiusOptions),
            'note' => $note,
            'labels' => $labels,
            'sort' => $sort,
            'sorts' => self::SORTS,
            'result' => $result,
            'job_names' => $jobNames,
            'params' => $params,
            'weights' => $this->settings->weights(SettingKey::SearchWeights),
            'searched' => $request->query->has('lieu') || $request->query->has('lieu_libelle'),
        ]);
        $response->headers->set('X-Robots-Tag', 'noindex, follow');

        return $response;
    }

    private function job(string $slug): ?JobIndexEntry
    {
        if ('' === $slug) {
            return null;
        }
        $job = $this->queries->ask(new JobBySlug($slug));

        return $job instanceof JobIndexEntry ? $job : null;
    }

    /** @return list<JobIndexEntry> métiers de la même catégorie (filtre « Métier », multi) */
    private function categoryJobs(JobIndexEntry $job): array
    {
        $entries = $this->queries->ask(new JobIndexEntries());

        return array_values(array_filter(\is_array($entries) ? $entries : [], static fn (mixed $e): bool => $e instanceof JobIndexEntry && $e->searchable && $e->categorySlug === $job->categorySlug));
    }

    /** Lieu par code INSEE (suggestion choisie) ou, à défaut, par le libellé saisi (commune la plus peuplée qui correspond). */
    private function place(string $insee, string $label): ?CommuneSummary
    {
        if (1 === preg_match('/^(\d{2}|2A|2B)\d{3}$/', $insee)) {
            $found = $this->queries->ask(new CommunesByInsee([$insee]));
            if (\is_array($found) && ($found[0] ?? null) instanceof CommuneSummary) {
                return $found[0];
            }
        }
        if (mb_strlen($label) >= 2) {
            $found = $this->queries->ask(new FindCommunes(mb_substr((string) preg_replace('/\s*\(.*$/', '', $label), 0, 60), 1));
            if (\is_array($found) && ($found[0] ?? null) instanceof CommuneSummary) {
                return $found[0];
            }
        }

        return null;
    }

    /** @return array<string, string> nom des métiers affichés sur les cartes */
    private function jobNames(ArtisanSearchResult $result): array
    {
        $ids = array_values(array_unique(array_merge([], ...array_map(static fn ($h): array => $h->artisan->jobIds, $result->hits))));
        $entries = [] === $ids ? [] : $this->queries->ask(new JobIndexEntries($ids));
        $names = [];
        foreach (\is_array($entries) ? $entries : [] as $entry) {
            if ($entry instanceof JobIndexEntry) {
                $names[$entry->id] = $entry->name;
            }
        }

        return $names;
    }

    private static function label(CommuneSummary $commune): string
    {
        return [] === $commune->postalCodes ? $commune->name : \sprintf('%s (%s)', $commune->name, min($commune->postalCodes));
    }
}
