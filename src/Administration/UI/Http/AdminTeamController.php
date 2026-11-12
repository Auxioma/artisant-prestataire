<?php

declare(strict_types=1);

namespace App\Administration\UI\Http;

use App\Administration\Application\Port\CurrentAdmin;
use App\Administration\Application\Port\Reauthentication;
use App\Administration\Application\Team\AdminMemberView;
use App\Administration\Application\Team\ChangeAdminRole;
use App\Administration\Application\Team\DisableAdmin;
use App\Administration\Application\Team\EnableAdmin;
use App\Administration\Application\Team\InvitationToken;
use App\Administration\Application\Team\InviteAdmin;
use App\Administration\Application\Team\RenewAdminInvitation;
use App\Administration\Application\Team\TeamMembers;
use App\Administration\Application\Team\TeamRejected;
use App\Administration\Domain\Admin\AdminAction;
use App\Administration\Domain\Admin\AdminModule;
use App\Administration\Domain\Admin\AdminRole;
use App\Administration\Domain\Admin\AdminRuleViolation;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Domain\Identifier\IdGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * BO14 (Figma 186:6097) — équipe et rôles : liste, invitation (e-mail I-02), changement de rôle, désactivation,
 * nouvelle invitation ; matrice des permissions des rôles prédéfinis (lecture, P-06). Les actions sur les comptes
 * exigent une ré-authentification récente (ADR-0013).
 */
#[Route('/admin/equipe')]
final class AdminTeamController extends AbstractController
{
    public function __construct(private readonly CommandBus $commands, private readonly QueryBus $queries, private readonly IdGenerator $ids, private readonly CurrentAdmin $admin)
    {
    }

    #[Route('', name: 'admin_team', methods: ['GET'])]
    #[IsGranted('admin.team.view')]
    public function index(Request $request): Response
    {
        $role = AdminRole::tryFrom($request->query->getString('role')) ?? AdminRole::Moderator;

        return $this->renderTeam($role, [], [], Response::HTTP_OK);
    }

    #[Route('/inviter', name: 'admin_team_invite', methods: ['POST'])]
    #[IsGranted('admin.team.create')]
    public function invite(Request $request): Response
    {
        $this->assertCsrf($request, 'admin_team_invite');
        $values = ['name' => trim($request->request->getString('name')), 'email' => trim($request->request->getString('email')), 'role' => $request->request->getString('role')];
        if (!$this->isGranted(Reauthentication::ATTRIBUTE)) {
            return $this->reauth();
        }
        try {
            $this->commands->dispatch(new InviteAdmin($this->ids->next(), $values['email'], $values['name'], $values['role'], InvitationToken::generate(), $this->me()));
            $this->addFlash('success', ['title' => 'Invitation envoyée', 'message' => \sprintf('%s recevra un lien d’activation par e-mail.', $values['email'])]);

            return $this->redirectToRoute('admin_team', status: Response::HTTP_SEE_OTHER);
        } catch (TeamRejected|AdminRuleViolation $e) {
            return $this->renderTeam(AdminRole::Moderator, $values, ['invite' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\InvalidArgumentException) {
            return $this->renderTeam(AdminRole::Moderator, $values, ['invite' => 'Adresse e-mail invalide.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    #[Route('/{id}', name: 'admin_team_update', requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['POST'])]
    #[IsGranted('admin.team.edit')]
    public function update(Request $request, string $id): Response
    {
        $this->assertCsrf($request, 'admin_team_update_'.$id);
        if (!$this->isGranted(Reauthentication::ATTRIBUTE)) {
            return $this->reauth();
        }
        $me = $this->me();
        $action = $request->request->getString('action');
        try {
            $this->commands->dispatch(match ($action) {
                'role' => new ChangeAdminRole($id, $request->request->getString('role'), $me),
                'disable' => new DisableAdmin($id, $me),
                'enable' => new EnableAdmin($id, $me),
                'reinvite' => new RenewAdminInvitation($id, InvitationToken::generate(), $me),
                default => throw new AccessDeniedHttpException(),
            });
            $this->addFlash('success', match ($action) {
                'role' => 'Rôle modifié.',
                'disable' => 'Administrateur désactivé : ses sessions sont fermées.',
                'enable' => 'Administrateur réactivé.',
                default => 'Nouvelle invitation envoyée.',
            });
        } catch (TeamRejected|AdminRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_team', status: Response::HTTP_SEE_OTHER);
    }

    /**
     * @param array<string, string> $values
     * @param array<string, string> $errors
     */
    private function renderTeam(AdminRole $matrixRole, array $values, array $errors, int $status): Response
    {
        $members = $this->queries->ask(new TeamMembers());

        return $this->render('admin/team.html.twig', [
            'members' => array_values(array_filter(\is_array($members) ? $members : [], static fn (mixed $m): bool => $m instanceof AdminMemberView)),
            'roles' => AdminRole::cases(),
            'matrix_role' => $matrixRole,
            'modules' => AdminModule::cases(),
            'actions' => AdminAction::cases(),
            'values' => $values + ['name' => '', 'email' => '', 'role' => AdminRole::Moderator->value],
            'errors' => $errors,
            'me' => $this->me(),
        ], new Response(status: $status));
    }

    private function reauth(): Response
    {
        $this->addFlash('info', 'Pour cette action, confirmez votre identité (mot de passe et code).');

        return $this->redirectToRoute('admin_reauth', ['cible' => $this->generateUrl('admin_team')], Response::HTTP_SEE_OTHER);
    }

    private function me(): string
    {
        return $this->admin->id() ?? throw new AccessDeniedHttpException();
    }

    private function assertCsrf(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Jeton CSRF invalide.');
        }
    }
}
