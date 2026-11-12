<?php

declare(strict_types=1);

namespace App\Identity\UI\Admin;

use App\Identity\Application\Admin\ClientList;
use App\Identity\Application\Admin\ClientListPage;
use App\Identity\Application\Admin\ClientRecord;
use App\Identity\Application\Admin\ReinstateUser;
use App\Identity\Application\Admin\SuspendUser;
use App\Identity\Application\EmailVerification\ResendEmailVerification;
use App\Identity\Application\PasswordReset\RequestPasswordReset;
use App\Identity\Domain\User\IdentityRuleViolation;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Security\CurrentAdministrator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * BO02 (Figma 186:694) — comptes clients : liste filtrée et paginée, fiche en panneau latéral, actions : renvoyer la
 * validation, envoyer un lien de réinitialisation, suspendre (motif + e-mail E-26) / réactiver. Hors périmètre :
 * modification, anonymisation (RGPD, T-705 / T-808), connexion « en tant que » (T-807).
 */
#[Route('/admin/clients')]
final class ClientsController extends AbstractController
{
    private const int PER_PAGE = 25;

    public function __construct(private readonly CommandBus $commands, private readonly QueryBus $queries, private readonly CurrentAdministrator $admin)
    {
    }

    #[Route('', name: 'admin_clients', methods: ['GET'])]
    #[IsGranted('admin.clients.view')]
    public function index(Request $request): Response
    {
        $filters = [
            'q' => mb_substr(trim($request->query->getString('q')), 0, 100),
            'statut' => $request->query->getString('statut'),
            'type' => $request->query->getString('type'),
        ];
        $page = $this->queries->ask(new ClientList($filters['q'], $filters['statut'] ?: null, $filters['type'] ?: null, max(1, $request->query->getInt('page', 1)), self::PER_PAGE));
        \assert($page instanceof ClientListPage);
        $selected = $request->query->getString('client');
        $record = '' === $selected ? null : $this->queries->ask(new ClientRecord($selected));

        return $this->render('admin/clients/index.html.twig', [
            'page' => $page,
            'filters' => $filters,
            'record' => \is_array($record) ? $record : null,
        ], new Response(status: '' !== $selected && !\is_array($record) ? Response::HTTP_NOT_FOUND : Response::HTTP_OK));
    }

    #[Route('/{id}/action', name: 'admin_clients_action', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    #[IsGranted('admin.clients.edit')]
    public function action(Request $request, string $id): Response
    {
        if (!$this->isCsrfTokenValid('admin_client_'.$id, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Jeton CSRF invalide.');
        }
        $record = $this->queries->ask(new ClientRecord($id));
        if (!\is_array($record) || !\is_string($record['email'] ?? null)) {
            throw $this->createNotFoundException();
        }
        $admin = $this->admin->id() ?? throw new AccessDeniedHttpException();
        try {
            $message = match ($request->request->getString('action')) {
                'resend' => $this->dispatch(new ResendEmailVerification(userId: $id), 'Lien de validation renvoyé (si l’adresse n’est pas déjà confirmée).'),
                'reset' => $this->dispatch(new RequestPasswordReset($record['email'], $request->getClientIp()), 'Lien de réinitialisation envoyé au titulaire (E-03).'),
                'suspend' => $this->dispatch(new SuspendUser($id, $admin, $request->request->getString('reason')), 'Compte suspendu : le titulaire est déconnecté et prévenu (E-26).'),
                'reinstate' => $this->dispatch(new ReinstateUser($id, $admin), 'Compte réactivé : le titulaire est prévenu (E-26).'),
                default => throw new AccessDeniedHttpException(),
            };
            $this->addFlash('success', $message);
        } catch (IdentityRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_clients', ['client' => $id], Response::HTTP_SEE_OTHER);
    }

    private function dispatch(object $command, string $message): string
    {
        \assert($command instanceof \App\Shared\Application\Bus\Command);
        $this->commands->dispatch($command);

        return $message;
    }
}
