<?php

declare(strict_types=1);

namespace App\Shared\UI\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/** Sonde de vivacité (liveness) pour l'orchestrateur ; ne révèle aucune information interne. */
final class HealthController
{
    #[Route('/healthz', name: 'shared_health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $response = new JsonResponse(['status' => 'ok']);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
