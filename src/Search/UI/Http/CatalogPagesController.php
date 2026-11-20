<?php

declare(strict_types=1);

namespace App\Search\UI\Http;

use App\Catalog\Application\Geo\Query\CommuneSummary;
use App\Catalog\Application\Geo\Query\Departments;
use App\Catalog\Application\Geo\Query\SeoCommune;
use App\Catalog\Application\Geo\Query\SeoCommuneBySlug;
use App\Catalog\Application\Geo\Query\SeoCommunes;
use App\Catalog\Application\Query\CategoryBySlug;
use App\Catalog\Application\Query\CategoryPage;
use App\Catalog\Application\Query\CategorySummary;
use App\Catalog\Application\Query\JobBySlug;
use App\Catalog\Application\Query\JobIndexEntries;
use App\Catalog\Application\Query\JobIndexEntry;
use App\Catalog\Application\Query\MainCategories;
use App\Search\Application\Artisan\ArtisanHit;
use App\Search\Application\Artisan\ArtisanSearchResult;
use App\Search\Application\Artisan\SearchArtisans;
use App\Search\Application\Directory\ArtisanCountsByJob;
use App\Search\Application\Directory\CountArtisans;
use App\Search\Application\Directory\FeaturedArtisans;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pages publiques du référentiel (CDC annexe A) : P03 `/categories`, P04 `/categories/{categorie}`, P05 `/metiers/{metier}`,
 * `/{metier}/{ville-cp}`, `/artisans/{ville-cp}` et `/artisans/departement/{code}`. Les pages ville n'existent que si
 * elles sont activées (BO07.1) ; RG-023 : indexables seulement à partir de `seo.min_pros_index` professionnels, sinon
 * noindex et professionnels des communes voisines (anti « pages vides »).
 */
final class CatalogPagesController extends AbstractController
{
    private const string SLUG = '[a-z0-9]+(?:-[a-z0-9]+)*';
    private const string CITY = '[a-z0-9]+(?:-[a-z0-9]+)*-[0-9]{5}';
    private const int ALL_JOBS = 1000;
    private const int CARD_JOBS = 5;
    private const int FEATURED = 8;
    private const int LISTED = 10;
    private const int LINKS = 10;
    private const int DEPARTMENT_CITIES = 30;

    public function __construct(private readonly QueryBus $queries, private readonly SettingsProvider $settings)
    {
    }

    #[Route('/categories', name: 'catalog_categories', methods: ['GET'])]
    public function categories(): Response
    {
        $summaries = $this->queries->ask(new MainCategories(self::ALL_JOBS));
        $jobs = $this->allJobs();
        $categories = [];
        foreach (\is_array($summaries) ? $summaries : [] as $summary) {
            if (!$summary instanceof CategorySummary) {
                continue;
            }
            $categoryJobs = array_values(array_filter($jobs, static fn (JobIndexEntry $j): bool => $j->searchable && $j->categorySlug === $summary->slug));
            $categories[] = [
                'summary' => $summary,
                'first_jobs' => \array_slice($summary->jobs, 0, self::CARD_JOBS),
                'count' => $this->count(array_map(static fn (JobIndexEntry $j): string => $j->id, $categoryJobs)),
                // Filtre instantané P03.2 : catégorie, métiers et synonymes.
                'terms' => mb_strtolower(implode(' ', array_merge([$summary->name], ...array_map(static fn (JobIndexEntry $j): array => [$j->name, ...$j->synonyms], $categoryJobs)))),
            ];
        }

        return $this->render('catalog/categories.html.twig', ['categories' => $categories]);
    }

    #[Route('/categories/{slug}', name: 'catalog_category', requirements: ['slug' => self::SLUG], methods: ['GET'])]
    public function category(string $slug): Response
    {
        $category = $this->queries->ask(new CategoryBySlug($slug));
        if (!$category instanceof CategoryPage) {
            throw new NotFoundHttpException('Catégorie introuvable.');
        }
        $all = $this->allJobs();
        $jobs = array_values(array_filter($all, static fn (JobIndexEntry $j): bool => $j->searchable && $j->categorySlug === $category->slug));
        $jobIds = array_map(static fn (JobIndexEntry $j): string => $j->id, $jobs);

        return $this->render('catalog/category.html.twig', [
            'category' => $category,
            'jobs' => $jobs,
            'job_counts' => $this->countsByJob(new ArtisanCountsByJob()),
            'total' => $this->count($jobIds),
            'featured' => [] === $jobIds ? [] : $this->featured($jobIds, self::FEATURED),
            'job_names' => self::names($all),
        ]);
    }

    #[Route('/metiers/{slug}', name: 'catalog_job', requirements: ['slug' => self::SLUG], methods: ['GET'])]
    public function job(string $slug): Response
    {
        $job = $this->jobOrNotFound($slug);
        $all = $this->allJobs();
        $total = $this->count([$job->id]);

        return $this->local([
            'kind' => 'job',
            'job' => $job,
            'total' => $total,
            'indexable' => true,
            'hits' => $this->featured([$job->id], self::LISTED),
            'neighbours' => [],
            'cities' => $this->largestCities(),
            'related_jobs' => array_values(array_filter($all, static fn (JobIndexEntry $j): bool => $j->searchable && $j->categorySlug === $job->categorySlug && $j->id !== $job->id)),
            'job_names' => self::names($all),
        ]);
    }

    #[Route('/{job}/{city}', name: 'catalog_job_city', requirements: ['job' => self::SLUG, 'city' => self::CITY], methods: ['GET'], priority: -10)]
    public function jobCity(string $job, string $city): Response
    {
        $entry = $this->jobOrNotFound($job);
        $commune = $this->cityOrNotFound($city);
        $all = $this->allJobs();
        $result = $this->search($commune->commune->insee, [$entry->id], null);
        $indexable = $result->total >= $this->settings->int(SettingKey::SeoMinProsIndex);
        $counts = $this->countsByJob(new ArtisanCountsByJob(communeInsee: $commune->commune->insee));

        return $this->local([
            'kind' => 'job_city',
            'job' => $entry,
            'commune' => $commune,
            'radius' => $result->radiusKm,
            'total' => $result->total,
            'indexable' => $indexable,
            'hits' => \array_slice($result->hits, 0, self::LISTED),
            'neighbours' => $indexable ? [] : $this->neighbours($commune->commune->insee, [$entry->id], $result->hits),
            'cities' => $this->nearCities($commune->commune->insee),
            'related_jobs' => \array_slice(array_values(array_filter($all, static fn (JobIndexEntry $j): bool => $j->searchable && $j->id !== $entry->id && ($counts[$j->id] ?? 0) > 0)), 0, self::LINKS),
            'job_counts' => $counts,
            'job_names' => self::names($all),
        ]);
    }

    #[Route('/artisans/departement/{code}', name: 'catalog_department', requirements: ['code' => '[0-9]{2,3}|2[AB]'], methods: ['GET'])]
    public function department(string $code): Response
    {
        $departments = $this->queries->ask(new Departments());
        $name = \is_array($departments) ? ($departments[$code] ?? null) : null;
        if (!\is_string($name)) {
            throw new NotFoundHttpException('Département introuvable.');
        }
        $all = $this->allJobs();
        $total = $this->count([], $code);
        $counts = $this->countsByJob(new ArtisanCountsByJob($code));
        $cities = $this->queries->ask(SeoCommunes::ofDepartment($code, self::DEPARTMENT_CITIES));

        return $this->local([
            'kind' => 'department',
            'department' => ['code' => $code, 'name' => $name],
            'total' => $total,
            'indexable' => $total >= $this->settings->int(SettingKey::SeoMinProsIndex),
            'hits' => $this->featured([], self::LISTED, $code),
            'neighbours' => [],
            'cities' => array_values(array_filter(\is_array($cities) ? $cities : [], static fn (mixed $c): bool => $c instanceof CommuneSummary)),
            'related_jobs' => array_values(array_filter($all, static fn (JobIndexEntry $j): bool => $j->searchable && ($counts[$j->id] ?? 0) > 0)),
            'job_counts' => $counts,
            'job_names' => self::names($all),
        ]);
    }

    #[Route('/artisans/{city}', name: 'catalog_city', requirements: ['city' => self::CITY], methods: ['GET'])]
    public function city(string $city): Response
    {
        $commune = $this->cityOrNotFound($city);
        $all = $this->allJobs();
        $result = $this->search($commune->commune->insee, [], null);
        $indexable = $result->total >= $this->settings->int(SettingKey::SeoMinProsIndex);
        $counts = $this->countsByJob(new ArtisanCountsByJob(communeInsee: $commune->commune->insee));

        return $this->local([
            'kind' => 'city',
            'commune' => $commune,
            'radius' => $result->radiusKm,
            'total' => $result->total,
            'indexable' => $indexable,
            'hits' => \array_slice($result->hits, 0, self::LISTED),
            'neighbours' => $indexable ? [] : $this->neighbours($commune->commune->insee, [], $result->hits),
            'cities' => $this->nearCities($commune->commune->insee),
            'related_jobs' => array_values(array_filter($all, static fn (JobIndexEntry $j): bool => $j->searchable && ($counts[$j->id] ?? 0) > 0)),
            'job_counts' => $counts,
            'job_names' => self::names($all),
        ]);
    }

    /** @param array<string, mixed> $context */
    private function local(array $context): Response
    {
        $response = $this->render('catalog/local.html.twig', $context + [
            'job' => null,
            'commune' => null,
            'department' => null,
            'radius' => null,
            'job_counts' => [],
            'min_index' => $this->settings->int(SettingKey::SeoMinProsIndex),
        ]);
        if (true !== $context['indexable']) {
            $response->headers->set('X-Robots-Tag', 'noindex, follow');
        }

        return $response;
    }

    private function jobOrNotFound(string $slug): JobIndexEntry
    {
        $job = $this->queries->ask(new JobBySlug($slug));

        return $job instanceof JobIndexEntry ? $job : throw new NotFoundHttpException('Métier introuvable.');
    }

    /** Page ville activée (BO07.1) ; sinon 404 (aucune page programmatique pour une commune non retenue). */
    private function cityOrNotFound(string $slug): SeoCommune
    {
        $commune = $this->queries->ask(new SeoCommuneBySlug($slug));

        return $commune instanceof SeoCommune && $commune->pageEnabled ? $commune : throw new NotFoundHttpException('Page ville introuvable.');
    }

    /** @param list<string> $jobIds */
    private function search(string $insee, array $jobIds, ?int $radius): ArtisanSearchResult
    {
        $result = $this->queries->ask(new SearchArtisans($insee, $radius, $jobIds));
        \assert($result instanceof ArtisanSearchResult);

        return $result;
    }

    /**
     * Moins de `seo.min_pros_index` professionnels : ceux des communes voisines (rayon maximal proposé), sans doublon.
     *
     * @param list<string>     $jobIds
     * @param list<ArtisanHit> $already
     *
     * @return list<ArtisanHit>
     */
    private function neighbours(string $insee, array $jobIds, array $already): array
    {
        $options = $this->settings->intList(SettingKey::SearchRadiusOptions);
        $radius = [] === $options ? null : max($options);
        $seen = array_map(static fn (ArtisanHit $h): string => $h->artisan->profileId, $already);
        $hits = array_filter($this->search($insee, $jobIds, $radius)->hits, static fn (ArtisanHit $h): bool => !\in_array($h->artisan->profileId, $seen, true));

        return \array_slice(array_values($hits), 0, self::LISTED);
    }

    /**
     * @param list<string> $jobIds
     *
     * @return list<ArtisanHit>
     */
    private function featured(array $jobIds, int $limit, ?string $department = null): array
    {
        $hits = $this->queries->ask(new FeaturedArtisans($jobIds, $limit, $department));

        return array_values(array_filter(\is_array($hits) ? $hits : [], static fn (mixed $h): bool => $h instanceof ArtisanHit));
    }

    /** @param list<string> $jobIds */
    private function count(array $jobIds, ?string $department = null): int
    {
        $count = $this->queries->ask(new CountArtisans($jobIds, $department));

        return \is_int($count) ? $count : 0;
    }

    /** @return array<string, int> */
    private function countsByJob(ArtisanCountsByJob $query): array
    {
        $counts = $this->queries->ask($query);
        $result = [];
        foreach (\is_array($counts) ? $counts : [] as $id => $n) {
            if (\is_string($id) && \is_int($n)) {
                $result[$id] = $n;
            }
        }

        return $result;
    }

    /** @return list<CommuneSummary> */
    private function nearCities(string $insee): array
    {
        $cities = $this->queries->ask(SeoCommunes::near($insee, self::LINKS));

        return array_values(array_filter(\is_array($cities) ? $cities : [], static fn (mixed $c): bool => $c instanceof CommuneSummary));
    }

    /** @return list<CommuneSummary> villes les plus peuplées dont la page est activée (maillage de la page métier) */
    private function largestCities(): array
    {
        $cities = $this->queries->ask(SeoCommunes::largest(self::DEPARTMENT_CITIES));

        return array_values(array_filter(\is_array($cities) ? $cities : [], static fn (mixed $c): bool => $c instanceof CommuneSummary));
    }

    /** @return list<JobIndexEntry> */
    private function allJobs(): array
    {
        $entries = $this->queries->ask(new JobIndexEntries());

        return array_values(array_filter(\is_array($entries) ? $entries : [], static fn (mixed $e): bool => $e instanceof JobIndexEntry));
    }

    /**
     * @param list<JobIndexEntry> $jobs
     *
     * @return array<string, string>
     */
    private static function names(array $jobs): array
    {
        $names = [];
        foreach ($jobs as $job) {
            $names[$job->id] = $job->name;
        }

        return $names;
    }
}
