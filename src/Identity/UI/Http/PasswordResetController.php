<?php

declare(strict_types=1);

namespace App\Identity\UI\Http;

use App\Identity\Application\EmailVerification\SecretToken;
use App\Identity\Application\Password\PasswordPolicy;
use App\Identity\Application\Password\WeakPassword;
use App\Identity\Application\PasswordReset\RequestPasswordReset;
use App\Identity\Application\PasswordReset\ResetLinkHolder;
use App\Identity\Application\PasswordReset\ResetLinkState;
use App\Identity\Application\PasswordReset\ResetLinkStatus;
use App\Identity\Application\PasswordReset\ResetLinkUnusable;
use App\Identity\Application\PasswordReset\ResetPassword;
use App\Identity\UI\Form\PasswordResetType;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Security\IpAnonymizer;
use App\Shared\Domain\Exception\InvalidValue;
use App\Shared\Domain\ValueObject\Email;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * A05 — mot de passe oublié (Figma 230:12452) et nouveau mot de passe (230:12498). Réponse identique que le compte
 * existe ou non ; 3 demandes / h par compte et 10 / h par IP ; lien à usage unique, 1 h ; connexion automatique.
 */
final class PasswordResetController extends AbstractController
{
    private const string CSRF_REQUEST = 'password_forgotten';

    /** @param UserProviderInterface<\Symfony\Component\Security\Core\User\UserInterface> $users */
    public function __construct(
        private readonly CommandBus $commands,
        private readonly QueryBus $queries,
        private readonly PasswordPolicy $passwordPolicy,
        private readonly IpAnonymizer $ipAnonymizer,
        private readonly Security $security,
        #[Autowire(service: 'App\Identity\Infrastructure\Security\UserProvider')]
        private readonly UserProviderInterface $users,
        #[Autowire(service: 'limiter.password_reset_account')]
        private readonly RateLimiterFactoryInterface $accountLimiter,
        #[Autowire(service: 'limiter.password_reset_ip')]
        private readonly RateLimiterFactoryInterface $ipLimiter,
    ) {
    }

    #[Route('/mot-de-passe-oublie', name: 'identity_password_forgotten', methods: ['GET', 'POST'])]
    public function request(Request $request): Response
    {
        $email = trim($request->request->getString('email'));
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid(self::CSRF_REQUEST, $request->request->getString('_token'))) {
                throw new AccessDeniedHttpException('Jeton CSRF invalide.');
            }
            try {
                $canonical = new Email($email)->canonical();
            } catch (InvalidValue) {
                $canonical = null;
                $error = 'Adresse email invalide.';
            }
            if (null !== $canonical) {
                $ip = $request->getClientIp();
                // Au-delà des limites, rien n'est envoyé mais la réponse ne change pas (aucune information divulguée).
                $allowed = $this->ipLimiter->create($ip ?? 'unknown')->consume()->isAccepted()
                    && $this->accountLimiter->create(hash('sha256', $canonical))->consume()->isAccepted();
                if ($allowed) {
                    $this->commands->dispatch(new RequestPasswordReset($email, $ip, $this->ipAnonymizer->hash($ip)));
                }

                return $this->render('auth/password_forgotten.html.twig', ['email' => $email, 'sent' => true, 'error' => null, 'csrf_id' => self::CSRF_REQUEST]);
            }
        }

        return $this->render('auth/password_forgotten.html.twig', [
            'email' => '' === $email ? $request->query->getString('email') : $email,
            'sent' => false,
            'error' => $error,
            'csrf_id' => self::CSRF_REQUEST,
        ], new Response(status: null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/reinitialiser/{token}', name: 'identity_password_reset', requirements: ['token' => SecretToken::PATTERN], methods: ['GET', 'POST'])]
    public function reset(Request $request, string $token): Response
    {
        $state = $this->queries->ask(new ResetLinkStatus($token));
        \assert($state instanceof ResetLinkState);
        if (ResetLinkState::Valid !== $state) {
            return $this->noReferrer($this->render('auth/password_reset.html.twig', ['form' => null], new Response(status: Response::HTTP_GONE)));
        }

        $form = $this->createForm(PasswordResetType::class, null, ['password_min_length' => $this->passwordPolicy->minLength()]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $password = $form->get('password')->getData();
            $password = \is_string($password) ? $password : '';
            try {
                $email = $this->holderEmail($token);
                $this->commands->dispatch(new ResetPassword($token, $password, $request->getClientIp()));
                // A05.2 : connexion automatique ; les autres sessions sont fermées (empreinte du mot de passe changée).
                $this->security->login($this->users->loadUserByIdentifier($email), 'form_login', 'main');
                $this->addFlash('success', 'Votre mot de passe a été modifié. Vous êtes connecté.');

                return $this->redirectToRoute('public_home', status: Response::HTTP_SEE_OTHER);
            } catch (WeakPassword $e) {
                $form->get('password')->get('first')->addError(new FormError(match ($e->reason) {
                    WeakPassword::COMPROMISED => 'Ce mot de passe est trop courant ou a fuité, choisissez-en un autre.',
                    WeakPassword::CONTAINS_EMAIL => 'Votre mot de passe ne doit pas contenir votre adresse e-mail.',
                    WeakPassword::TOO_LONG => 'Votre mot de passe ne doit pas dépasser 128 caractères.',
                    default => \sprintf('Votre mot de passe doit contenir au moins %d caractères.', $e->minLength),
                }));
            } catch (ResetLinkUnusable) {
                return $this->noReferrer($this->render('auth/password_reset.html.twig', ['form' => null], new Response(status: Response::HTTP_GONE)));
            }
        }

        return $this->noReferrer($this->render('auth/password_reset.html.twig', ['form' => $form], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK)));
    }

    /** Adresse du titulaire du lien, lue avant la réinitialisation pour la connexion automatique. */
    private function holderEmail(string $token): string
    {
        $email = $this->queries->ask(new ResetLinkHolder($token));
        if (!\is_string($email)) {
            throw new ResetLinkUnusable('Lien inutilisable.');
        }

        return $email;
    }

    private function noReferrer(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
