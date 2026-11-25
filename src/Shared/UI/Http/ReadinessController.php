<?php

declare(strict_types=1);

namespace App\Shared\UI\Http;

use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Sonde de disponibilité (readiness, T-906a) : l'instance ne reçoit du trafic que si PostgreSQL et Redis (cache,
 * sessions, files) répondent. 503 sinon ; le détail part dans les journaux, jamais dans la réponse.
 */
final readonly class ReadinessController
{
    private const string PROBE_KEY = 'readyz';

    public function __construct(
        private Connection $connection,
        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $cache,
        private LoggerInterface $logger,
    ) {
    }

    #[Route('/readyz', name: 'shared_readiness', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $failed = [];
        try {
            $this->connection->executeQuery('SELECT 1')->fetchOne();
        } catch (\Throwable $e) {
            $failed['database'] = $e->getMessage();
        }
        try {
            $this->cache->hasItem(self::PROBE_KEY);
        } catch (\Throwable $e) {
            $failed['redis'] = $e->getMessage();
        }
        if ([] !== $failed) {
            $this->logger->error('Sonde de disponibilité en échec', ['failed' => array_keys($failed), 'errors' => $failed]);
        }
        $response = new JsonResponse(['status' => [] === $failed ? 'ok' : 'unavailable'], [] === $failed ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
