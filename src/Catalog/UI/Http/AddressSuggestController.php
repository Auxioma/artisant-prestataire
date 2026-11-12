<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http;

use App\Catalog\Application\Geo\Port\GeocodedAddress;
use App\Catalog\Application\Geo\Query\SuggestAddresses;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Integration\ExternalServiceUnavailable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET /api/adresses?q= — autocomplétion d'adresse (BAN) servie par la plateforme : la politique de sécurité du contenu
 * n'autorise pas le navigateur à appeler l'API externe, et la saisie n'est jamais journalisée. Les coordonnées ne sont
 * pas renvoyées (seul le serveur s'en sert, au géocodage de l'adresse retenue).
 */
final class AddressSuggestController extends AbstractController
{
    private const int LIMIT = 5;
    private const int MIN_LENGTH = 3;

    #[Route('/api/adresses', name: 'catalog_address_suggest', methods: ['GET'])]
    public function suggest(Request $request, QueryBus $queries, #[Autowire(service: 'limiter.autocomplete')] RateLimiterFactory $limiter): JsonResponse
    {
        $q = trim($request->query->getString('q'));
        if (mb_strlen($q) < self::MIN_LENGTH) {
            return $this->noStore(new JsonResponse(['items' => []]));
        }
        if (!$limiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return $this->noStore(new JsonResponse(['items' => [], 'error' => 'rate_limited'], Response::HTTP_TOO_MANY_REQUESTS));
        }
        try {
            $addresses = $queries->ask(new SuggestAddresses($q, self::LIMIT));
        } catch (ExternalServiceUnavailable) {
            return $this->noStore(new JsonResponse(['items' => [], 'error' => 'unavailable'], Response::HTTP_SERVICE_UNAVAILABLE));
        }
        $items = [];
        foreach (\is_array($addresses) ? $addresses : [] as $a) {
            if ($a instanceof GeocodedAddress) {
                $items[] = ['label' => $a->label, 'postal_code' => $a->postalCode, 'insee' => $a->inseeCode, 'city' => $a->city];
            }
        }

        return $this->noStore(new JsonResponse(['items' => $items]));
    }

    private function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
