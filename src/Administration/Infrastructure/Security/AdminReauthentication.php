<?php

declare(strict_types=1);

namespace App\Administration\Infrastructure\Security;

use App\Administration\Application\Port\Reauthentication;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Domain\Time\Clock;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * ADR-0013 : mot de passe + TOTP saisis depuis moins de `admin.reauth_max_age_min` minutes pour les actions sensibles
 * (rôles, remboursements, clés, exports de données personnelles…). La connexion complète vaut ré-authentification.
 */
final readonly class AdminReauthentication implements Reauthentication
{
    public const string SESSION_KEY = 'admin.reauth_at';

    public function __construct(
        private SettingsProvider $settings,
        private Clock $clock,
        private RequestStack $requests,
        private Security $security,
        private UserPasswordHasherInterface $hasher,
        #[Autowire(service: 'scheb_two_factor.security.totp_authenticator')]
        private TotpAuthenticatorInterface $totp,
    ) {
    }

    public function attempt(#[\SensitiveParameter] string $password, string $code): bool
    {
        $user = $this->security->getUser();
        $session = $this->session();
        if (!$user instanceof AdminSecurityUser || null === $session || !$this->hasher->isPasswordValid($user, $password) || !$this->totp->checkCode($user, $code)) {
            return false;
        }
        $this->mark($session);

        return true;
    }

    public function isRecent(): bool
    {
        $session = $this->session();
        $at = $session?->get(self::SESSION_KEY);

        return \is_int($at) && $this->clock->now()->getTimestamp() - $at <= 60 * $this->settings->int(SettingKey::AdminReauthMaxAgeMin);
    }

    public function mark(SessionInterface $session): void
    {
        $session->set(self::SESSION_KEY, $this->clock->now()->getTimestamp());
    }

    private function session(): ?SessionInterface
    {
        $request = $this->requests->getCurrentRequest();

        return null !== $request && $request->hasSession() ? $request->getSession() : null;
    }
}
