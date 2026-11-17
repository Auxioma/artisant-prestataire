<?php

declare(strict_types=1);

namespace App\Identity\UI\Http;

use App\Identity\Application\Client\ClientProfilePage;
use App\Identity\Application\Client\ClientProfileView;
use App\Identity\Application\Command\ChangePassword;
use App\Identity\Application\Command\InvalidCurrentPassword;
use App\Identity\Application\EmailVerification\RequestEmailChange;
use App\Identity\Application\Password\WeakPassword;
use App\Identity\Application\Port\KnownDevices;
use App\Identity\Application\Registration\RegistrationRejected;
use App\Identity\Application\Security\ConnectedDevices;
use App\Identity\Application\Security\RevokeDevices;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Security\CurrentUser;
use App\Shared\Domain\Exception\InvalidValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * C07.2 « Sécurité » (onglet de C07, Figma 231:12840 — pas de maquette dédiée, P-124) : changer d'adresse e-mail (lien
 * de confirmation envoyé à la nouvelle adresse), changer de mot de passe (mot de passe actuel exigé), appareils
 * connectés (déconnecter un appareil ou tous les autres).
 */
#[Route('/espace-client/securite')]
final class ClientSecurityController extends AbstractController
{
    private const string CSRF = 'client_security';

    /** @param UserProviderInterface<\Symfony\Component\Security\Core\User\UserInterface> $users */
    public function __construct(
        private readonly QueryBus $queries,
        private readonly CommandBus $commands,
        private readonly CurrentUser $user,
        private readonly Security $security,
        #[Autowire(service: 'App\Identity\Infrastructure\Security\UserProvider')]
        private readonly UserProviderInterface $users,
    ) {
    }

    #[Route('', name: 'client_security', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->page($request, []);
    }

    #[Route('/e-mail', name: 'client_security_email', methods: ['POST'])]
    public function changeEmail(Request $request): Response
    {
        $this->assertCsrf($request);
        $email = trim($request->request->getString('newEmail'));
        try {
            $this->commands->dispatch(new RequestEmailChange($this->userId(), $request->request->getString('emailPassword'), $email, $request->getClientIp()));
        } catch (InvalidCurrentPassword) {
            return $this->page($request, ['emailPassword' => 'Mot de passe actuel incorrect.'], ['newEmail' => $email]);
        } catch (InvalidValue) {
            return $this->page($request, ['newEmail' => 'Adresse e-mail invalide.'], ['newEmail' => $email]);
        } catch (RegistrationRejected) {
            return $this->page($request, ['newEmail' => 'Les adresses temporaires ne sont pas acceptées.'], ['newEmail' => $email]);
        }
        $this->addFlash('success', 'Un lien de confirmation a été envoyé à la nouvelle adresse : votre adresse actuelle reste valable jusqu’à sa confirmation.');

        return $this->redirectToRoute('client_security', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/mot-de-passe', name: 'client_security_password', methods: ['POST'])]
    public function changePassword(Request $request): Response
    {
        $this->assertCsrf($request);
        $new = $request->request->getString('newPassword');
        if ($new !== $request->request->getString('confirmPassword')) {
            return $this->page($request, ['confirmPassword' => 'Les deux mots de passe ne correspondent pas.']);
        }
        try {
            $this->commands->dispatch(new ChangePassword($this->userId(), $request->request->getString('currentPassword'), $new, $request->getClientIp()));
        } catch (InvalidCurrentPassword) {
            return $this->page($request, ['currentPassword' => 'Mot de passe actuel incorrect.']);
        } catch (WeakPassword $e) {
            return $this->page($request, ['newPassword' => match ($e->reason) {
                WeakPassword::COMPROMISED => 'Ce mot de passe est trop courant ou a fuité, choisissez-en un autre.',
                WeakPassword::CONTAINS_EMAIL => 'Votre mot de passe ne doit pas contenir votre adresse e-mail.',
                WeakPassword::TOO_LONG => 'Votre mot de passe ne doit pas dépasser 128 caractères.',
                default => \sprintf('Votre mot de passe doit contenir au moins %d caractères.', $e->minLength),
            }]);
        }
        // Les autres sessions sont fermées (empreinte du mot de passe) ; celle-ci est renouvelée.
        $this->security->login($this->users->loadUserByIdentifier($this->profile()->email), 'form_login', 'main');
        $this->addFlash('success', 'Votre mot de passe est modifié ; vos autres appareils devront se reconnecter.');

        return $this->redirectToRoute('client_security', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/appareils', name: 'client_security_devices', methods: ['POST'])]
    public function revoke(Request $request): Response
    {
        $this->assertCsrf($request);
        $device = $request->request->getString('device');
        $this->commands->dispatch(new RevokeDevices($this->userId(), '' === $device ? null : $device, $this->currentDeviceHash($request), $request->getClientIp()));
        $this->addFlash('success', '' === $device ? 'Vos autres appareils sont déconnectés.' : 'L’appareil est déconnecté.');

        return $this->redirectToRoute('client_security', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $values
     */
    private function page(Request $request, array $errors, array $values = []): Response
    {
        return $this->render('client/profile/security.html.twig', [
            'profile' => $this->profile(),
            'devices' => $this->queries->ask(new ConnectedDevices($this->userId(), $this->currentDeviceHash($request))),
            'errors' => $errors,
            'values' => $values,
            'csrf_id' => self::CSRF,
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    private function currentDeviceHash(Request $request): ?string
    {
        $device = $request->cookies->getString(KnownDevices::COOKIE);

        return '' === $device ? null : hash('sha256', $device);
    }

    private function profile(): ClientProfileView
    {
        $profile = $this->queries->ask(new ClientProfilePage($this->userId()));

        return $profile instanceof ClientProfileView ? $profile : throw new NotFoundHttpException();
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException();
        }
    }

    private function userId(): string
    {
        return $this->user->id() ?? throw new AccessDeniedHttpException();
    }
}
