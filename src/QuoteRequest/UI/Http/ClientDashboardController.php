<?php

declare(strict_types=1);

namespace App\QuoteRequest\UI\Http;

use App\QuoteRequest\Application\Client\ClientRequestPage;
use App\QuoteRequest\Application\Client\ClientRequests;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Identity\UserContact;
use App\Shared\Application\Identity\UserContactDetails;
use App\Shared\Application\Security\CurrentUser;
use App\Shared\Domain\Time\Clock;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * C01 (/espace-client, Figma 22:2) — tableau de bord client : bienvenue, actions requises, indicateurs, accès rapide,
 * dernières demandes. Les blocs devis, rendez-vous, favoris et messages arrivent avec leurs lots (P-76).
 */
final class ClientDashboardController extends AbstractController
{
    private const int LATEST = 3;

    public function __construct(private readonly QueryBus $queries, private readonly CurrentUser $user, private readonly Clock $clock)
    {
    }

    #[Route('/espace-client', name: 'client_dashboard', methods: ['GET'])]
    public function index(): Response
    {
        $userId = $this->user->id() ?? throw new AccessDeniedHttpException();
        $page = $this->queries->ask(new ClientRequests($userId, perPage: self::LATEST));
        \assert($page instanceof ClientRequestPage);
        $contact = $this->queries->ask(new UserContact($userId));

        return $this->render('client/dashboard.html.twig', [
            'page' => $page,
            'first_name' => $contact instanceof UserContactDetails ? $contact->firstName : null,
            'initials' => $contact instanceof UserContactDetails ? mb_strtoupper(mb_substr($contact->firstName, 0, 1).mb_substr($contact->lastName, 0, 1)) : '',
            'today' => $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Paris')),
        ]);
    }
}
