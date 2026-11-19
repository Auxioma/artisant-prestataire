<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Domain\User\UserId;
use App\Identity\Domain\User\UserKind;
use App\Identity\Domain\User\UserRepository;
use App\Identity\Domain\User\UserStatus;
use App\Shared\Application\Security\ImpersonationContext;
use App\Shared\Application\Security\ImpersonationSession;
use App\Shared\Application\Security\ImpersonationTickets;
use App\Shared\Domain\Time\Clock;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * ADR-0013 — ouvre la session d'un utilisateur à partir d'un ticket du back-office (usage unique, une minute). Ni mot
 * de passe, ni « rester connecté », ni alerte de nouvel appareil, ni date de dernière connexion (LoginSubscriber
 * l'ignore) ; la connexion assistée est notée en session pour le bandeau, le blocage financier et le journal.
 */
final class ImpersonationAuthenticator extends AbstractAuthenticator
{
    public const string ROUTE = 'identity_impersonation_enter';

    private ?ImpersonationSession $session = null;

    public function __construct(
        private readonly ImpersonationTickets $tickets,
        private readonly UserRepository $users,
        private readonly UrlGeneratorInterface $urls,
        private readonly Clock $clock,
    ) {
    }

    public function supports(Request $request): bool
    {
        return self::ROUTE === $request->attributes->get('_route');
    }

    public function authenticate(Request $request): Passport
    {
        $token = $request->attributes->get('token');
        $ticket = $this->tickets->consume(\is_string($token) ? $token : '');
        if (null === $ticket) {
            throw new CustomUserMessageAuthenticationException('Lien de connexion assistée expiré ou déjà utilisé.');
        }
        $user = $this->users->find(new UserId($ticket->userId));
        if (null === $user || UserStatus::Anonymized === $user->status()) {
            throw new CustomUserMessageAuthenticationException('Ce compte n’existe plus.');
        }
        $now = $this->clock->now();
        $this->session = new ImpersonationSession($ticket->adminId, $ticket->adminName, $ticket->userId, $ticket->returnUrl, $now, $now->modify(\sprintf('+%d minutes', ImpersonationSession::MAX_MINUTES)));

        return new SelfValidatingPassport(new UserBadge($ticket->userId, static fn (): SecurityUser => SecurityUser::fromUser($user)));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        $request->getSession()->migrate(true);
        $request->getSession()->set(ImpersonationContext::SESSION_KEY, $this->session);
        $user = $token->getUser();

        return new RedirectResponse($this->urls->generate($user instanceof SecurityUser && UserKind::Artisan === $user->kind ? 'pro_dashboard' : 'client_dashboard'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $session = $request->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('error', $exception->getMessageKey());
        }

        return new RedirectResponse($this->urls->generate('public_home'));
    }
}
