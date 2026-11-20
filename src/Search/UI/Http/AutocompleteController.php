<?php

declare(strict_types=1);

namespace App\Search\UI\Http;

use App\Search\Application\Port\JobDocument;
use App\Search\Application\Port\PlaceDocument;
use App\Search\Application\Query\Autocomplete;
use App\Search\Application\Query\Suggestions;
use App\Shared\Application\Bus\QueryBus;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * P01.2 : GET /api/suggestions?type=metier|lieu&q=… — texte brut (mise en évidence faite côté client, sans HTML injecté),
 * limité en débit (anti-scraping, cadrage §5.7), réponse publique mise en cache brièvement.
 */
final readonly class AutocompleteController
{
    private const int CACHE_SECONDS = 300;

    public function __construct(
        private QueryBus $queries,
        #[Autowire(service: 'limiter.autocomplete')]
        private RateLimiterFactoryInterface $limiter,
    ) {
    }

    #[Route('/api/suggestions', name: 'search_autocomplete', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $limit = $this->limiter->create($request->getClientIp() ?? 'unknown')->consume();
        if (!$limit->isAccepted()) {
            $response = new JsonResponse(['error' => 'Trop de requêtes, réessayez dans un instant.'], Response::HTTP_TOO_MANY_REQUESTS);
            $response->headers->set('Retry-After', (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()));

            return $response;
        }

        $scope = $request->query->getString('type', Autocomplete::SCOPE_JOBS);
        $term = $request->query->getString('q');
        if (!\in_array($scope, [Autocomplete::SCOPE_JOBS, Autocomplete::SCOPE_PLACES], true) || mb_strlen($term) > Autocomplete::MAX_LENGTH) {
            return new JsonResponse(['error' => 'Paramètres invalides.'], Response::HTTP_BAD_REQUEST);
        }

        $suggestions = $this->queries->ask(new Autocomplete($term, $scope));
        \assert($suggestions instanceof Suggestions);

        $response = new JsonResponse([
            'metiers' => array_map(static fn (JobDocument $j): array => ['nom' => $j->name, 'slug' => $j->slug, 'categorie' => $j->categoryName, 'synonymes' => $j->synonyms], $suggestions->jobs),
            'lieux' => array_map(static fn (PlaceDocument $p): array => ['nom' => $p->name, 'slug' => $p->slug, 'insee' => $p->insee, 'codesPostaux' => $p->postalCodes, 'departement' => '' === $p->departmentName ? $p->departmentCode : $p->departmentCode.' — '.$p->departmentName], $suggestions->places),
        ]);
        $response->setPublic();
        $response->setMaxAge($suggestions->degraded ? 0 : self::CACHE_SECONDS);
        $response->headers->set('X-Search-Mode', $suggestions->degraded ? 'fallback' : 'index');

        return $response;
    }
}
