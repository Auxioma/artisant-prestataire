<?php

declare(strict_types=1);

namespace App\Administration\Infrastructure\Security;

use App\Administration\Application\Team\RecordAdminLogin;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvent;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvents;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

/**
 * Protections du back-office (ADR-0013) :
 * - hôte dédié (`ADMIN_HOST`, ex. admin.trouvemoi.com) : le back-office n'existe pas ailleurs (404) ;
 * - liste blanche IP optionnelle (`admin.ip_allowlist`) : 403 avant toute authentification ;
 * - déconnexion après `admin.idle_timeout_min` minutes d'inactivité ;
 * - connexion complète (mot de passe + TOTP) : horodatage, journal d'audit, ré-authentification fraîche ;
 * - échecs de mot de passe et de code journalisés (sans le mot de passe ni le code).
 */
final readonly class AdminAccessSubscriber implements EventSubscriberInterface
{
    public const string LAST_ACTIVITY = 'admin.last_activity';
    private const string PATH = '#^/admin(/|$)#';

    public function __construct(
        private TokenStorageInterface $tokens,
        private SettingsProvider $settings,
        private AdminReauthentication $reauth,
        private CommandBus $commands,
        private AuditTrail $audit,
        private IdGenerator $ids,
        private Clock $clock,
        private UrlGeneratorInterface $urls,
        #[Autowire('%env(default::ADMIN_HOST)%')]
        private ?string $adminHost = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => [['guardEntry', 16], ['enforceIdleTimeout', 7]],
            TwoFactorAuthenticationEvents::COMPLETE => 'onLoginComplete',
            TwoFactorAuthenticationEvents::FAILURE => 'onCodeFailure',
            LoginFailureEvent::class => 'onPasswordFailure',
        ];
    }

    public function guardEntry(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !self::isAdminPath($request)) {
            return;
        }
        if (null !== $this->adminHost && '' !== $this->adminHost && 0 !== strcasecmp($request->getHost(), $this->adminHost)) {
            throw new NotFoundHttpException();
        }
        $allowlist = $this->settings->stringList(SettingKey::AdminIpAllowlist);
        if ([] !== $allowlist && !IpUtils::checkIp((string) $request->getClientIp(), $allowlist)) {
            throw new AccessDeniedHttpException('Adresse IP non autorisée pour le back-office.');
        }
    }

    public function enforceIdleTimeout(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !self::isAdminPath($request) || !$request->hasSession() || !$this->tokens->getToken()?->getUser() instanceof AdminSecurityUser) {
            return;
        }
        $session = $request->getSession();
        $now = $this->clock->now()->getTimestamp();
        $last = $session->get(self::LAST_ACTIVITY);
        if (\is_int($last) && $now - $last > 60 * $this->settings->int(SettingKey::AdminIdleTimeoutMin)) {
            $this->tokens->setToken(null);
            $session->invalidate();
            $event->setResponse(new RedirectResponse($this->urls->generate('admin_login', ['expiree' => 1])));

            return;
        }
        $session->set(self::LAST_ACTIVITY, $now);
    }

    public function onLoginComplete(TwoFactorAuthenticationEvent $event): void
    {
        $user = $event->getToken()->getUser();
        if (!$user instanceof AdminSecurityUser) {
            return;
        }
        $request = $event->getRequest();
        $session = $request->getSession();
        $session->set(self::LAST_ACTIVITY, $this->clock->now()->getTimestamp());
        $this->reauth->mark($session); // la connexion complète vaut ré-authentification
        $this->commands->dispatch(new RecordAdminLogin($user->id, $request->getClientIp()));
    }

    public function onCodeFailure(TwoFactorAuthenticationEvent $event): void
    {
        $user = $event->getToken()->getUser();
        if ($user instanceof AdminSecurityUser) {
            $this->log('admin.2fa_failed', $user->id, $event->getRequest());
        }
    }

    public function onPasswordFailure(LoginFailureEvent $event): void
    {
        if ('admin' === $event->getFirewallName()) {
            $this->log('admin.login_failed', null, $event->getRequest());
        }
    }

    private function log(string $action, ?string $adminId, Request $request): void
    {
        $this->audit->record(new AuditRecord(
            id: $this->ids->next(),
            occurredAt: $this->clock->now(),
            actorType: 'system',
            actorId: null,
            action: $action,
            subjectType: null === $adminId ? null : 'admin_user',
            subjectId: $adminId,
            ip: $request->getClientIp(),
        ));
    }

    private static function isAdminPath(Request $request): bool
    {
        return 1 === preg_match(self::PATH, $request->getPathInfo());
    }
}
