<?php

declare(strict_types=1);

namespace App\Search\UI\Http;

use App\Catalog\Application\Geo\Query\CommunesNear;
use App\Catalog\Application\Geo\Query\CommuneSummary;
use App\Shared\Application\Bus\QueryBus;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * P01.2 — géocodage inverse de la position du navigateur (après autorisation) : commune la plus proche, au format
 * « Le Havre (76600) ». Les coordonnées ne sont ni journalisées ni conservées.
 */
final readonly class NearestCommuneController
{
    private const int SEARCH_RADIUS_KM = 15;
    private const float LAT_MIN = -90.0;
    private const float LAT_MAX = 90.0;
    private const float LNG_MIN = -180.0;
    private const float LNG_MAX = 180.0;

    public function __construct(
        private QueryBus $queries,
        #[Autowire(service: 'limiter.autocomplete')]
        private RateLimiterFactoryInterface $limiter,
    ) {
    }

    #[Route('/api/commune-proche', name: 'search_nearest_commune', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        if (!$this->limiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'Trop de requêtes.'], Response::HTTP_TOO_MANY_REQUESTS);
        }
        $lat = $request->query->get('lat');
        $lng = $request->query->get('lng');
        if (!is_numeric($lat) || !is_numeric($lng) || (float) $lat < self::LAT_MIN || (float) $lat > self::LAT_MAX || (float) $lng < self::LNG_MIN || (float) $lng > self::LNG_MAX) {
            return new JsonResponse(['error' => 'Coordonnées invalides.'], Response::HTTP_BAD_REQUEST);
        }
        $found = $this->queries->ask(new CommunesNear((float) $lat, (float) $lng, self::SEARCH_RADIUS_KM, 1));
        $commune = \is_array($found) ? ($found[0] ?? null) : null;
        if (!$commune instanceof CommuneSummary) {
            return new JsonResponse(['error' => 'Aucune commune trouvée.'], Response::HTTP_NOT_FOUND, ['Cache-Control' => 'no-store']);
        }

        return new JsonResponse([
            'insee' => $commune->insee,
            'label' => [] === $commune->postalCodes ? $commune->name : \sprintf('%s (%s)', $commune->name, min($commune->postalCodes)),
        ], Response::HTTP_OK, ['Cache-Control' => 'no-store']);
    }
}
