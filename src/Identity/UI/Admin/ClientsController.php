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
use App\Shared\Application\Admin\AdminExportJournal;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Security\CurrentAdministrator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
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
    private const int EXPORT_BATCH = 100;
    private const int EXPORT_MAX = 50000;

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

    /** BO15.2 — export CSV de la liste filtrée : données personnelles, donc journalisé (admin.export). */
    #[Route('/export.csv', name: 'admin_clients_export', methods: ['GET'])]
    #[IsGranted('admin.clients.export')]
    public function export(Request $request, AdminExportJournal $journal): StreamedResponse
    {
        $filters = array_filter(['q' => mb_substr(trim($request->query->getString('q')), 0, 100), 'statut' => $request->query->getString('statut'), 'type' => $request->query->getString('type')], static fn (string $v): bool => '' !== $v);
        $rows = [];
        for ($page = 1; \count($rows) < self::EXPORT_MAX; ++$page) {
            $result = $this->queries->ask(new ClientList($filters['q'] ?? '', ($filters['statut'] ?? '') ?: null, ($filters['type'] ?? '') ?: null, $page, self::EXPORT_BATCH));
            \assert($result instanceof ClientListPage);
            $rows = [...$rows, ...$result->items];
            if ($page >= $result->pages()) {
                break;
            }
        }
        $journal->record('clients', $filters, \count($rows), $request->getClientIp());
        $response = new StreamedResponse(static function () use ($rows): void {
            $out = fopen('php://output', 'w');
            \assert(\is_resource($out));
            fputcsv($out, ['nom', 'email', 'telephone', 'type', 'statut', 'email_verifie', 'inscrit_le', 'derniere_connexion'], ';', '"', '');
            $zone = new \DateTimeZone('Europe/Paris');
            foreach ($rows as $r) {
                fputcsv($out, [$r['name'], $r['email'], $r['phone'] ?? '', $r['type'], $r['status'], $r['verified'] ? 'oui' : 'non', $r['created_at']->setTimezone($zone)->format('Y-m-d H:i'), $r['last_login_at']?->setTimezone($zone)->format('Y-m-d H:i') ?? ''], ';', '"', '');
            }
            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="clients.csv"');
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    #[Route('/{id}/action', name: 'admin_clients_action', requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['POST'])]
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
