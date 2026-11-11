<?php

declare(strict_types=1);

namespace App\Identity\UI\Http;

use App\Identity\Application\EmailVerification\ConfirmEmail;
use App\Identity\Application\EmailVerification\EmailTokenState;
use App\Identity\Application\EmailVerification\EmailTokenStatus;
use App\Identity\Application\EmailVerification\InvalidEmailToken;
use App\Identity\Application\EmailVerification\ResendEmailVerification;
use App\Identity\Application\EmailVerification\SecretToken;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A06 — validation d'e-mail (Figma 230:12568) : succès, lien expiré (renvoyer), déjà validé (se connecter), invalide.
 * Le renvoi ne révèle jamais si une adresse est inscrite (même message dans tous les cas).
 */
final class EmailVerificationController extends AbstractController
{
    private const string CSRF_ID = 'email_verification_resend';

    public function __construct(
        private readonly CommandBus $commands,
        private readonly QueryBus $queries,
        #[Autowire(service: 'limiter.email_verification_resend')]
        private readonly RateLimiterFactoryInterface $resendLimiter,
    ) {
    }

    #[Route('/verification-email/{token}', name: 'identity_verify_email', requirements: ['token' => SecretToken::PATTERN], methods: ['GET'])]
    public function verify(Request $request, string $token): Response
    {
        $state = $this->queries->ask(new EmailTokenStatus($token));
        \assert($state instanceof EmailTokenState);

        if (EmailTokenState::Valid === $state) {
            try {
                $this->commands->dispatch(new ConfirmEmail($token, $request->getClientIp()));
            } catch (InvalidEmailToken $e) {
                $state = $e->state; // second clic simultané, adresse prise entre-temps…
            }
        }
        $verified = EmailTokenState::Valid === $state;
        if ($verified && null !== $this->getUser()) {
            $this->addFlash('success', 'Adresse e-mail confirmée. Vous pouvez déposer des demandes et utiliser la messagerie.');
        }

        $response = $this->render('auth/email_verification.html.twig', [
            'state' => $verified ? 'verified' : $state->value,
            'token' => EmailTokenState::Expired === $state ? $token : null,
            'csrf_id' => self::CSRF_ID,
        ], new Response(status: EmailTokenState::Invalid === $state ? Response::HTTP_NOT_FOUND : Response::HTTP_OK));
        // Le jeton figure dans l'URL : jamais conservé en cache (et <meta name="referrer" content="no-referrer"> dans la page).
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    #[Route('/verification-email/renvoyer', name: 'identity_resend_verification', methods: ['POST'], priority: 10)]
    public function resend(Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF_ID, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Jeton CSRF invalide.');
        }
        if ($this->resendLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            // Utilisateur connecté : son identifiant de connexion (e-mail) ; sinon adresse saisie à l'inscription ou ancien lien.
            $email = $this->getUser()?->getUserIdentifier() ?? $request->getSession()->get(RegistrationController::SESSION_EMAIL);
            $token = $request->request->getString('token');
            $this->commands->dispatch(new ResendEmailVerification(
                email: \is_string($email) ? $email : null,
                previousToken: '' === $token ? null : $token,
            ));
        }
        $this->addFlash('info', 'Si votre adresse doit encore être confirmée, un nouveau lien vient de vous être envoyé.');

        return $this->render('auth/email_verification.html.twig', ['state' => 'resent', 'token' => null, 'csrf_id' => self::CSRF_ID]);
    }
}
