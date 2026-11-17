<?php

declare(strict_types=1);

namespace App\ArtisanProfile\UI\Http\Agenda;

use App\ArtisanProfile\Application\Agenda\IcalFeed;
use App\ArtisanProfile\Application\Agenda\IcalFeedView;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Domain\Time\Clock;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * PR06.3 — lien iCal privé et révocable (abonnement depuis Google Agenda, Outlook, Apple Calendrier) : rendez-vous et
 * absences de l'artisan, sans coordonnées de client. Le jeton fait office de mot de passe : réponse jamais indexée,
 * jamais mise en cache partagée, appels limités par IP.
 */
final class IcalFeedController extends AbstractController
{
    public function __construct(
        private readonly QueryBus $queries,
        private readonly Clock $clock,
        #[Target('ical_feed.limiter')]
        private readonly RateLimiterFactoryInterface $limiter,
    ) {
    }

    #[Route('/agenda/{token}.ics', name: 'agenda_ical', requirements: ['token' => '[0-9a-f]{48}'], methods: ['GET'])]
    public function __invoke(Request $request, string $token): Response
    {
        if (!$this->limiter->create($request->getClientIp() ?? 'inconnu')->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException();
        }
        $feed = $this->queries->ask(new IcalFeed($token));
        if (!$feed instanceof IcalFeedView) {
            throw new NotFoundHttpException();
        }

        return new Response(IcalWriter::write($feed, $this->clock->now(), $request->getHost()), Response::HTTP_OK, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="trouvemoi-agenda.ics"',
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
