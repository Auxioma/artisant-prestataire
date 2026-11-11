<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Application\Login\RecordLogin;
use App\Identity\Application\Port\HumanVerification;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Integration\ExternalServiceUnavailable;
use App\Shared\Application\Security\IpAnonymizer;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\RememberMeAuthenticator;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * RG-045 et E-05 autour du formulaire de connexion : blocage et captcha AVANT tout chargement de compte ou calcul
 * Argon2id (même comportement qu'un compte existe ou non), comptage des échecs, appareil de connexion, audit.
 */
final readonly class LoginSubscriber implements EventSubscriberInterface
{
    public const string DEVICE_COOKIE = 'tm_device';
    public const string SESSION_TRACK = 'identity.login_track';
    private const int DEVICE_COOKIE_DAYS = 400;
    private const string CAPTCHA_FIELD = 'cf-turnstile-response';

    public function __construct(
        private LoginAttemptGuard $guard,
        private HumanVerification $humans,
        private CommandBus $commands,
        private AuditTrail $audit,
        private IpAnonymizer $ipAnonymizer,
        private IdGenerator $ids,
        private Clock $clock,
        private RequestStack $requests,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Avant le chargement du compte (UserBadge) et la vérification du mot de passe
            CheckPassportEvent::class => ['onCheckPassport', 512],
            LoginFailureEvent::class => 'onLoginFailure',
            LoginSuccessEvent::class => 'onLoginSuccess',
        ];
    }

    public function onCheckPassport(CheckPassportEvent $event): void
    {
        if ($event->getAuthenticator() instanceof RememberMeAuthenticator) {
            return;
        }
        $badge = $event->getPassport()->getBadge(UserBadge::class);
        $email = $badge instanceof UserBadge ? $badge->getUserIdentifier() : null;
        $request = $this->requests->getCurrentRequest();
        $ip = $request?->getClientIp();

        $until = $this->guard->blockedUntil($email, $ip);
        if (null !== $until) {
            $minutes = max(1, (int) round(($until->getTimestamp() - $this->clock->now()->getTimestamp()) / 60));
            throw new CustomUserMessageAuthenticationException(\sprintf('Trop de tentatives. Réessayez dans %d minute%s ou réinitialisez votre mot de passe.', $minutes, $minutes > 1 ? 's' : ''));
        }
        if ($this->guard->requiresCaptcha($email, $ip)) {
            try {
                $human = $this->humans->verify($request?->request->getString(self::CAPTCHA_FIELD) ?? '', $ip, 'login');
            } catch (ExternalServiceUnavailable) {
                throw new CustomUserMessageAuthenticationException('Vérification anti-robot momentanément indisponible. Réessayez dans quelques minutes.');
            }
            if (!$human) {
                throw new CustomUserMessageAuthenticationException('Merci de confirmer que vous n’êtes pas un robot.');
            }
        }
    }

    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $request = $event->getRequest();
        $badge = $event->getPassport()?->getBadge(UserBadge::class);
        $email = $badge instanceof UserBadge ? $badge->getUserIdentifier() : $request->request->getString('_username');
        $this->guard->recordFailure($email, $request->getClientIp());
        $this->audit->record(new AuditRecord(
            id: $this->ids->next(),
            occurredAt: $this->clock->now(),
            actorType: 'system', // visiteur non authentifié : seule l'empreinte de l'adresse saisie est conservée
            actorId: null,
            action: 'security.login_failed',
            ip: $request->getClientIp(),
            context: ['email_hash' => hash('sha256', mb_strtolower(trim($email))), 'reason' => new \ReflectionClass($event->getException())->getShortName()],
        ));
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof SecurityUser) {
            return;
        }
        $request = $event->getRequest();
        $rememberMe = $event->getAuthenticator() instanceof RememberMeAuthenticator;
        if (!$rememberMe) {
            $this->guard->recordSuccess($user->email, $request->getClientIp());
        }

        $device = $request->cookies->getString(self::DEVICE_COOKIE);
        $newCookie = 1 !== preg_match('/^[A-Za-z0-9_-]{43}$/', $device);
        if ($newCookie) {
            $device = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        }
        $this->commands->dispatch(new RecordLogin(
            userId: $user->id,
            deviceToken: $device,
            userAgent: $request->headers->get('User-Agent'),
            ip: $request->getClientIp(),
            ipHash: $this->ipAnonymizer->hash($request->getClientIp()),
            method: $rememberMe ? 'remember_me' : 'password',
        ));
        if (!$rememberMe) {
            $request->getSession()->set(self::SESSION_TRACK, 'password');
        }

        $response = $event->getResponse();
        if ($newCookie && null !== $response) {
            // Identifiant d'appareil aléatoire (aucune empreinte navigateur) : sert uniquement à l'alerte E-05.
            $response->headers->setCookie(Cookie::create(self::DEVICE_COOKIE, $device, $this->clock->now()->modify(\sprintf('+%d days', self::DEVICE_COOKIE_DAYS)), '/', null, null, true, false, Cookie::SAMESITE_LAX));
        }
    }
}
