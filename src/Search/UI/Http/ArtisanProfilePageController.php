<?php

declare(strict_types=1);

namespace App\Search\UI\Http;

use App\ArtisanProfile\Application\Portfolio\PortfolioEditor;
use App\ArtisanProfile\Application\PublicProfile\PublicArtisanProfile;
use App\ArtisanProfile\Application\PublicProfile\PublicProfileView;
use App\ArtisanProfile\Domain\Profile\OpeningHours;
use App\ArtisanProfile\Domain\Profile\PublicPresentation;
use App\Catalog\Application\Geo\Query\CommunesByInsee;
use App\Catalog\Application\Geo\Query\CommuneSummary;
use App\Catalog\Application\Geo\Query\Departments;
use App\Catalog\Application\Query\JobIndexEntries;
use App\Catalog\Application\Query\JobIndexEntry;
use App\Search\Application\Artisan\ArtisanHit;
use App\Search\Application\Artisan\ArtisanSearchResult;
use App\Search\Application\Artisan\SearchArtisans;
use App\Search\UI\Twig\ArtisanUrlExtension;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Messaging\ArtisanResponseTime;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * P10 (Figma 98:763) — fiche artisan publique, indexable : `/pro/{nom}-{commune}-{id}` (une autre forme renvoie en 301
 * vers l'URL canonique). Artisan non public (non vérifié, suspendu, masqué RG-130) : 404 sans rien divulguer.
 * Balisage LocalBusiness ; aucune note agrégée tant qu'aucun avis n'est publié (jamais de note inventée).
 */
final class ArtisanProfilePageController extends AbstractController
{
    private const string UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
    private const int SIMILAR = 3;

    public function __construct(private readonly QueryBus $queries)
    {
    }

    #[Route('/pro/{slug}-{id}', name: 'public_artisan_profile', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*', 'id' => self::UUID], methods: ['GET'])]
    public function show(string $slug, string $id): Response
    {
        $profile = $this->profile($id);
        $canonical = ArtisanUrlExtension::slug($profile->name, $profile->communeName);
        if ($slug !== $canonical) {
            return $this->redirectToRoute('public_artisan_profile', ['slug' => $canonical, 'id' => $id], Response::HTTP_MOVED_PERMANENTLY);
        }

        $jobs = $this->jobs($profile->jobIds);
        $main = $jobs[$profile->mainJobId ?? ''] ?? (array_values($jobs)[0] ?? null);
        $similar = $this->similar($profile, $main);
        $similarJobs = $this->jobs(array_values(array_unique(array_merge([], ...array_map(static fn (ArtisanHit $h): array => $h->artisan->jobIds, $similar)))));

        return $this->render('artisan/profile.html.twig', [
            'profile' => $profile,
            'jobs' => $jobs,
            'main_job' => $main,
            'area_center' => null === $profile->areaCenterInsee ? null : $this->commune($profile->areaCenterInsee),
            'departments' => $this->departments($profile->areaDepartments),
            'similar' => $similar,
            'similar_jobs' => $similarJobs,
            'languages' => PublicPresentation::LANGUAGES,
            'clients' => PublicPresentation::CLIENTS,
            'hours_days' => OpeningHours::DAYS,
            'photo_kinds' => PortfolioEditor::KINDS,
            'response_minutes' => $this->queries->ask(new ArtisanResponseTime($id)),
        ]);
    }

    /** Ancien lien ou lien court : 301 vers l'URL canonique. */
    #[Route('/pro/{id}', name: 'public_artisan_profile_short', requirements: ['id' => self::UUID], methods: ['GET'])]
    public function short(string $id): RedirectResponse
    {
        $profile = $this->profile($id);

        return $this->redirectToRoute('public_artisan_profile', ['slug' => ArtisanUrlExtension::slug($profile->name, $profile->communeName), 'id' => $id], Response::HTTP_MOVED_PERMANENTLY);
    }

    private function profile(string $id): PublicProfileView
    {
        $profile = $this->queries->ask(new PublicArtisanProfile($id));

        return $profile instanceof PublicProfileView ? $profile : throw new NotFoundHttpException('Professionnel introuvable.');
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, JobIndexEntry>
     */
    private function jobs(array $ids): array
    {
        $entries = [] === $ids ? [] : $this->queries->ask(new JobIndexEntries($ids));
        $jobs = [];
        foreach (\is_array($entries) ? $entries : [] as $entry) {
            if ($entry instanceof JobIndexEntry) {
                $jobs[$entry->id] = $entry;
            }
        }

        return $jobs;
    }

    private function commune(string $insee): ?CommuneSummary
    {
        $found = $this->queries->ask(new CommunesByInsee([$insee]));

        return \is_array($found) && ($found[0] ?? null) instanceof CommuneSummary ? $found[0] : null;
    }

    /**
     * @param list<string> $codes
     *
     * @return array<string, string>
     */
    private function departments(array $codes): array
    {
        if ([] === $codes) {
            return [];
        }
        $all = $this->queries->ask(new Departments());
        $names = [];
        foreach ($codes as $code) {
            $names[$code] = \is_array($all) && \is_string($all[$code] ?? null) ? $all[$code] : $code;
        }

        return $names;
    }

    /** @return list<ArtisanHit> professionnels du même métier principal autour de la commune de l'artisan, sans lui */
    private function similar(PublicProfileView $profile, ?JobIndexEntry $job): array
    {
        if (null === $profile->communeInsee || null === $job) {
            return [];
        }
        $result = $this->queries->ask(new SearchArtisans($profile->communeInsee, jobIds: [$job->id]));
        if (!$result instanceof ArtisanSearchResult) {
            return [];
        }

        return \array_slice(array_values(array_filter($result->hits, static fn (ArtisanHit $h): bool => $h->artisan->profileId !== $profile->profileId)), 0, self::SIMILAR);
    }
}
