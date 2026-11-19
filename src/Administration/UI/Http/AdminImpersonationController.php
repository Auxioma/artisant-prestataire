<?php

declare(strict_types=1);

namespace App\Administration\UI\Http;

use App\Administration\Application\Logs\AuditLogReader;
use App\Administration\Application\Port\Reauthentication;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Security\CurrentAdministrator;
use App\Shared\Application\Security\ImpersonationTicket;
use App\Shared\Application\Security\ImpersonationTickets;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * BO02.2 / BO03.3 « Se connecter en tant que » (ADR-0013) — super-admin seulement, ré-authentification récente, motif
 * obligatoire, début journalisé ; un ticket à usage unique ouvre la session de l'utilisateur sur le site.
 */
final class AdminImpersonationController extends AbstractController
{
    private const int REASON_MIN = 5;
    private const int REASON_MAX = 300;

    public function __construct(
        #[Autowire('%env(default::ADMIN_HOST)%')]
        private readonly ?string $adminHost = null,
        #[Autowire('%env(default::DEFAULT_URI)%')]
        private readonly ?string $publicUri = null,
    ) {
    }

    #[Route('/admin/connexion-assistee/{userId}', name: 'admin_impersonate', requirements: ['userId' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function __invoke(Request $request, string $userId, CurrentAdministrator $admin, AuditLogReader $admins, ImpersonationTickets $tickets, AuditTrail $audit, IdGenerator $ids, Clock $clock): Response
    {
        if (!$this->isCsrfTokenValid('admin_impersonate_'.$userId, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Jeton CSRF invalide.');
        }
        $return = $request->request->getString('retour');
        $return = str_starts_with($return, '/admin/') ? $return : $this->generateUrl('admin_dashboard');
        if (!$this->isGranted(Reauthentication::ATTRIBUTE)) {
            $this->addFlash('info', 'Pour vous connecter en tant qu’utilisateur, confirmez votre identité (mot de passe et code).');

            return $this->redirectToRoute('admin_reauth', ['cible' => $return], Response::HTTP_SEE_OTHER);
        }
        $reason = trim($request->request->getString('motif'));
        if (mb_strlen($reason) < self::REASON_MIN || mb_strlen($reason) > self::REASON_MAX) {
            $this->addFlash('error', \sprintf('Indiquez le motif de la connexion assistée (%d à %d caractères).', self::REASON_MIN, self::REASON_MAX));

            return $this->redirect($return, Response::HTTP_SEE_OTHER);
        }
        $adminId = $admin->id() ?? throw new AccessDeniedHttpException();
        $now = $clock->now();
        $audit->record(new AuditRecord(
            id: $ids->next(),
            occurredAt: $now,
            actorType: 'admin',
            actorId: $adminId,
            action: 'admin.impersonation_started',
            subjectType: 'user',
            subjectId: $userId,
            ip: $request->getClientIp(),
            context: ['reason' => $reason],
        ));
        // Retour absolu : avec un hôte dédié au back-office (ADMIN_HOST), la connexion assistée a lieu sur le site public.
        $token = $tickets->issue(new ImpersonationTicket($adminId, $admins->admins()[$adminId] ?? 'Administrateur', $userId, $reason, $request->getSchemeAndHttpHost().$return, $now));
        $enter = $this->generateUrl('identity_impersonation_enter', ['token' => $token]);
        $dedicated = null !== $this->adminHost && '' !== $this->adminHost && null !== $this->publicUri && '' !== $this->publicUri;

        return $this->redirect(($dedicated ? rtrim((string) $this->publicUri, '/') : '').$enter, Response::HTTP_SEE_OTHER);
    }
}
