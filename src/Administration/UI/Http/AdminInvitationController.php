<?php

declare(strict_types=1);

namespace App\Administration\UI\Http;

use App\Administration\Application\Port\Totp;
use App\Administration\Application\Team\AcceptAdminInvitation;
use App\Administration\Application\Team\AdminMemberView;
use App\Administration\Application\Team\BackupCodes;
use App\Administration\Application\Team\InvitationDetails;
use App\Administration\Application\Team\InvitationToken;
use App\Administration\Application\Team\TeamRejected;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints\NotCompromisedPassword;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * BO14.1 — activation d'un compte invité, en une page : mot de passe + configuration de la 2FA (QR code ou clé),
 * confirmée par un premier code ; les codes de secours sont affichés une seule fois (jamais mis en cache).
 * Le secret TOTP en cours de configuration reste en session jusqu'à confirmation.
 */
final class AdminInvitationController extends AbstractController
{
    private const string SESSION_SECRET = 'admin.invitation_totp.';
    private const int PASSWORD_MAX = 128;

    public function __construct(
        private readonly CommandBus $commands,
        private readonly QueryBus $queries,
        private readonly Totp $totp,
        private readonly BackupCodes $backupCodes,
        private readonly SettingsProvider $settings,
    ) {
    }

    #[Route('/admin/invitation/{token}', name: 'admin_invitation', requirements: ['token' => '[A-Za-z0-9_-]{43}'], methods: ['GET', 'POST'])]
    public function __invoke(Request $request, string $token, ValidatorInterface $validator): Response
    {
        $invitation = $this->queries->ask(new InvitationDetails($token));
        if (!$invitation instanceof AdminMemberView) {
            return $this->render('admin/security/invitation_invalid.html.twig', [], $this->noStore(new Response(status: Response::HTTP_GONE)));
        }
        $session = $request->getSession();
        $key = self::SESSION_SECRET.InvitationToken::hash($token);
        $secret = $session->get($key);
        if (!\is_string($secret) || '' === $secret) {
            $secret = $this->totp->generateSecret();
            $session->set($key, $secret);
        }
        $minLength = $this->settings->int(SettingKey::AuthPasswordMinLength);
        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_invitation', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }
            $password = $request->request->getString('password');
            if (mb_strlen($password) < $minLength || mb_strlen($password) > self::PASSWORD_MAX) {
                $errors['password'] = \sprintf('Votre mot de passe doit contenir de %d à %d caractères.', $minLength, self::PASSWORD_MAX);
            } elseif (str_contains(mb_strtolower($password), mb_strtolower(strstr($invitation->email, '@', true) ?: $invitation->email))) {
                $errors['password'] = 'Votre mot de passe ne doit pas contenir votre adresse e-mail.';
            } elseif (\count($validator->validate($password, new NotCompromisedPassword(skipOnError: true))) > 0) {
                $errors['password'] = 'Ce mot de passe est trop courant ou a fuité, choisissez-en un autre.';
            } elseif ($password !== $request->request->getString('password_confirm')) {
                $errors['password_confirm'] = 'Les deux mots de passe ne correspondent pas.';
            }
            if ([] === $errors) {
                $codes = $this->backupCodes->generate();
                try {
                    $this->commands->dispatch(new AcceptAdminInvitation($token, $password, $secret, $request->request->getString('code'), $codes));
                    $session->remove($key);

                    return $this->render('admin/security/backup_codes.html.twig', ['codes' => $codes, 'name' => $invitation->name], $this->noStore(new Response()));
                } catch (TeamRejected $e) {
                    $errors[TeamRejected::TOTP_INVALID === $e->reason ? 'code' : 'form'] = $e->getMessage();
                }
            }
        }

        $uri = $this->totp->provisioningUri($secret, $invitation->email);

        return $this->render('admin/security/invitation.html.twig', [
            'invitation' => $invitation,
            'qr_svg' => new QRCode(new QROptions(['outputBase64' => false, 'svgAddXmlHeader' => false, 'drawLightModules' => false, 'cssClass' => 'tm-qr', 'svgUseFillAttributes' => false, 'connectPaths' => true]))->render($uri),
            'secret' => trim(chunk_split($secret, 4, ' ')),
            'min_length' => $minLength,
            'errors' => $errors,
        ], $this->noStore(new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY)));
    }

    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
