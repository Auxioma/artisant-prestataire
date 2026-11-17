<?php

declare(strict_types=1);

namespace App\ArtisanProfile\UI\Http;

use App\ArtisanProfile\Application\PublicProfile\ArtisanPhone;
use App\ArtisanProfile\Application\PublicProfile\RecordPhoneReveal;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Security\IpAnonymizer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RG-030 — téléphone affiché au clic (jamais dans le HTML de la fiche, ni indexé) : POST protégé (jeton sans état),
 * limité par adresse IP (limiter « phone_reveal ») et tracé pour les statistiques de l'artisan. Sans JavaScript, la
 * fiche est réaffichée avec le numéro.
 */
final class ArtisanPhoneController extends AbstractController
{
    public const string FLASH = 'artisan_phone';

    public function __construct(
        private readonly QueryBus $queries,
        private readonly CommandBus $commands,
        private readonly IpAnonymizer $ipAnonymizer,
        #[Autowire(service: 'limiter.phone_reveal')]
        private readonly RateLimiterFactoryInterface $limiter,
    ) {
    }

    #[Route('/pro/{id}/telephone', name: 'public_artisan_phone', requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['POST'])]
    public function __invoke(Request $request, string $id): Response
    {
        if (!$this->isCsrfTokenValid('phone_reveal', $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Jeton CSRF invalide.');
        }
        $phone = $this->queries->ask(new ArtisanPhone($id));
        if (!\is_string($phone)) {
            throw new NotFoundHttpException();
        }
        $limit = $this->limiter->create($request->getClientIp() ?? 'unknown')->consume();
        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException($limit->getRetryAfter()->getTimestamp() - time(), 'Trop de numéros affichés aujourd’hui. Réessayez demain ou envoyez un message.');
        }
        $this->commands->dispatch(new RecordPhoneReveal($id, $this->ipAnonymizer->hash($request->getClientIp())));

        $headers = ['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, nofollow'];
        if (str_contains((string) $request->headers->get('Accept'), 'application/json')) {
            return new JsonResponse(['phone' => $phone, 'href' => 'tel:'.str_replace(' ', '', $phone)], Response::HTTP_OK, $headers);
        }
        $this->addFlash(self::FLASH, $phone);

        return $this->redirectToRoute('public_artisan_profile_short', ['id' => $id, '_fragment' => 'contact'], Response::HTTP_SEE_OTHER);
    }
}
