<?php

declare(strict_types=1);

namespace App\Identity\UI\Http;

use App\Notification\Application\Preference\ChangeNotificationMatrix;
use App\Notification\Application\Preference\NotificationMatrix;
use App\Notification\Application\Preference\NotificationTopic;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Security\CurrentUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * C07.3 « Mes paramètres » / onglet « Mes préférences » : matrice sujet × canal (e-mail, notification sur le site ; SMS
 * annoncé), lignes indispensables verrouillées (CDC C07.3). Pas de maquette dédiée : P-124.
 */
final class ClientSettingsController extends AbstractController
{
    private const string CSRF = 'client_settings';
    /** Messages toujours envoyés, affichés verrouillés (aucun sujet réglable). */
    private const array ESSENTIAL = [
        'Sécurité du compte (mot de passe, nouvel appareil)',
        'Devis accepté et coordonnées de l’artisan',
        'Rendez-vous proposé, confirmé ou annulé',
    ];

    public function __construct(private readonly QueryBus $queries, private readonly CommandBus $commands, private readonly CurrentUser $user)
    {
    }

    #[Route('/espace-client/parametres', name: 'client_settings', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $userId = $this->user->id() ?? throw new AccessDeniedHttpException();
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid(self::CSRF, $request->request->getString('_token'))) {
                throw new AccessDeniedHttpException();
            }
            $posted = $request->request->all('matrix');
            $matrix = [];
            foreach (NotificationTopic::cases() as $topic) {
                foreach (NotificationTopic::CHANNELS as $channel) {
                    $matrix[$topic->value][$channel] = \is_array($posted[$topic->value] ?? null) && isset($posted[$topic->value][$channel]);
                }
            }
            $this->commands->dispatch(new ChangeNotificationMatrix($userId, $matrix));
            $this->addFlash('success', 'Vos préférences de notification sont enregistrées.');

            return $this->redirectToRoute('client_settings', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('client/profile/settings.html.twig', [
            'topics' => NotificationTopic::cases(),
            'matrix' => $this->queries->ask(new NotificationMatrix($userId)),
            'essential' => self::ESSENTIAL,
            'csrf_id' => self::CSRF,
        ]);
    }
}
