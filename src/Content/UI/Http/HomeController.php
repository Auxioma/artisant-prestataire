<?php

declare(strict_types=1);

namespace App\Content\UI\Http;

use App\Catalog\Application\Geo\Query\CommunesByInsee;
use App\Catalog\Application\Geo\Query\CommuneSummary;
use App\Catalog\Application\Query\JobIndexEntry;
use App\Content\Application\Home\PublishedHome;
use App\Search\Application\Artisan\ArtisanHit;
use App\Search\Application\Artisan\ArtisanSearchResult;
use App\Search\Application\Artisan\SearchArtisans;
use App\Search\Application\Directory\FeaturedArtisans;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * P01 (Figma 78:3) — page d'accueil : blocs publiés en BO10.1 (contenu initial du CDC tant que rien n'est publié).
 * Bloc « près de chez vous » (P01.6) : sélection nationale au rendu, puis rechargée autour du dernier lieu mémorisé.
 */
final class HomeController extends AbstractController
{
    private const int RADIUS_WIDENING = 2;

    public function __construct(private readonly QueryBus $queries, private readonly HomePageData $data, private readonly SettingsProvider $settings)
    {
    }

    #[Route('/', name: 'public_home', methods: ['GET'])]
    public function __invoke(): Response
    {
        $blocks = $this->queries->ask(new PublishedHome());

        return $this->render('content/home.html.twig', $this->data->build(HomePageData::blocks($blocks), $this->audience()) + ['preview' => false]);
    }

    /**
     * P01.6 — professionnels autour d'une commune (RG-011, RG-012 : distance puis note bayésienne) ; rayon doublé puis
     * département si personne ne correspond (« Peu de professionnels à {ville} : voici ceux des environs »).
     */
    #[Route('/accueil/professionnels', name: 'home_pros', methods: ['GET'])]
    public function pros(Request $request): Response
    {
        $count = max(1, min($request->query->getInt('nombre', $this->settings->int(SettingKey::HomeProsCount)), $this->settings->int(SettingKey::HomeProsCount) * self::RADIUS_WIDENING));
        $radius = max(1, min($request->query->getInt('rayon', $this->settings->int(SettingKey::SearchDefaultRadiusKm)), $this->settings->int(SettingKey::ProRadiusMaxKm)));
        $commune = $this->commune($request->query->getString('lieu'));
        $widened = false;
        $hits = [];
        if (null !== $commune) {
            $hits = $this->near($commune->insee, $radius, $count);
            if ([] === $hits) {
                $widened = true;
                $hits = $this->near($commune->insee, $radius * self::RADIUS_WIDENING, $count);
            }
            if ([] === $hits) {
                $found = $this->queries->ask(new FeaturedArtisans([], $count, $commune->departmentCode));
                $hits = array_values(array_filter(\is_array($found) ? $found : [], static fn (mixed $h): bool => $h instanceof ArtisanHit));
            }
        }
        if ([] === $hits) {
            $hits = $this->data->featured($count);
        }

        return $this->render('content/home/_pros_list.html.twig', [
            'hits' => $hits,
            'commune' => $commune,
            'widened' => $widened,
            'job_names' => array_map(static fn (JobIndexEntry $j): string => $j->name, $this->data->jobs()),
        ]);
    }

    /** @return list<ArtisanHit> */
    private function near(string $insee, int $radius, int $count): array
    {
        $result = $this->queries->ask(new SearchArtisans($insee, $radius, sort: SearchArtisans::SORT_DISTANCE));
        if (!$result instanceof ArtisanSearchResult) {
            return [];
        }
        $min = $this->settings->int(SettingKey::ProMinCompletionFeatured);

        return \array_slice(array_values(array_filter($result->hits, static fn (ArtisanHit $h): bool => $h->artisan->completion >= $min)), 0, $count);
    }

    private function commune(string $insee): ?CommuneSummary
    {
        if (1 !== preg_match('/^(\d{2}|2A|2B)\d{3}$/', $insee)) {
            return null;
        }
        $found = $this->queries->ask(new CommunesByInsee([$insee]));

        return \is_array($found) && ($found[0] ?? null) instanceof CommuneSummary ? $found[0] : null;
    }

    private function audience(): string
    {
        return match (true) {
            null === $this->getUser() => 'guest',
            $this->isGranted('ROLE_ARTISAN') => 'artisan',
            default => 'client',
        };
    }
}
