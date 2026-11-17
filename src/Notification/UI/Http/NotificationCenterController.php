<?php

declare(strict_types=1);

namespace App\Notification\UI\Http;

use App\Notification\Application\Center\NotificationAction;
use App\Notification\Application\Center\NotificationCenter;
use App\Notification\Application\Center\NotificationCenterView;
use App\Notification\Application\Center\NotificationType;
use App\Notification\Application\Port\InAppNotifications;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Security\CurrentUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * C08 « Notifications » (Figma 231:13176) — client (/espace-client/notifications) et artisan (/espace-pro/notifications) :
 * liste (icône par famille, texte, date relative, non lues en gras), filtre par famille, « Tout marquer comme lu »,
 * ouverture = lecture puis lien. Conservation 90 jours.
 */
final class NotificationCenterController extends AbstractController
{
    private const string CSRF = 'notifications';
    private const string UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
    private const array SPACES = ['client' => 'client/_space.html.twig', 'pro' => 'pro/_space.html.twig'];

    public function __construct(private readonly QueryBus $queries, private readonly CommandBus $commands, private readonly CurrentUser $user, private readonly InAppNotifications $notifications)
    {
    }

    #[Route('/espace-client/notifications', name: 'client_notifications', methods: ['GET'], defaults: ['space' => 'client'])]
    #[Route('/espace-pro/notifications', name: 'pro_notifications', methods: ['GET'], defaults: ['space' => 'pro'])]
    public function index(Request $request, string $space): Response
    {
        $type = NotificationType::tryFrom($request->query->getString('type'));
        $view = $this->queries->ask(new NotificationCenter($this->userId(), $type?->value, max(1, $request->query->getInt('page', 1))));
        \assert($view instanceof NotificationCenterView);

        return $this->render('notifications/index.html.twig', [
            'view' => $view,
            'type' => $type,
            'types' => NotificationType::cases(),
            'space' => $space,
            'layout' => self::SPACES[$space],
            'csrf_id' => self::CSRF,
        ]);
    }

    /** Ouverture : la notification est lue, puis l'utilisateur suit son lien (ou revient à la liste). */
    #[Route('/espace-client/notifications/{id}', name: 'client_notification_open', requirements: ['id' => self::UUID], methods: ['GET'], defaults: ['space' => 'client'])]
    #[Route('/espace-pro/notifications/{id}', name: 'pro_notification_open', requirements: ['id' => self::UUID], methods: ['GET'], defaults: ['space' => 'pro'])]
    public function open(string $space, string $id): Response
    {
        $userId = $this->userId();
        $this->commands->dispatch(new NotificationAction($userId, $id));

        return $this->redirect($this->notifications->link($userId, $id) ?? $this->generateUrl($space.'_notifications'));
    }

    #[Route('/espace-client/notifications/tout-lu', name: 'client_notifications_read', methods: ['POST'], defaults: ['space' => 'client'], priority: 1)]
    #[Route('/espace-pro/notifications/tout-lu', name: 'pro_notifications_read', methods: ['POST'], defaults: ['space' => 'pro'], priority: 1)]
    public function readAll(Request $request, string $space): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException();
        }
        $this->commands->dispatch(new NotificationAction($this->userId()));
        $this->addFlash('success', 'Toutes vos notifications sont marquées comme lues.');

        return $this->redirectToRoute($space.'_notifications', [], Response::HTTP_SEE_OTHER);
    }

    private function userId(): string
    {
        return $this->user->id() ?? throw new AccessDeniedHttpException();
    }
}
