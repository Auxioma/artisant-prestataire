<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * RG-045 — échecs de connexion comptés par compte ET par IP (fenêtre glissante, seuils administrables
 * `auth.login_max_attempts` / `auth.login_window_min`) :
 *   - seuil atteint (compte ou IP) → captcha exigé (Turnstile) ;
 *   - double du seuil pour le couple compte + IP → connexion refusée jusqu'à la fin de la fenêtre (palier de délai, P-44).
 * Le compteur par compte protège des attaques distribuées ; le blocage dur, limité au couple, évite qu'un tiers puisse
 * verrouiller le compte de quelqu'un d'autre depuis une autre IP.
 */
final readonly class LoginAttemptGuard
{
    private CacheStorage $storage;

    public function __construct(
        private SettingsProvider $settings,
        #[Autowire(service: 'cache.rate_limiter')]
        CacheItemPoolInterface $pool,
    ) {
        $this->storage = new CacheStorage($pool);
    }

    public function requiresCaptcha(?string $email, ?string $ip): bool
    {
        return $this->exhausted($this->limiter('account', $this->max(), self::accountKey($email)))
            || $this->exhausted($this->limiter('ip', $this->max(), self::ipKey($ip)));
    }

    /** @return \DateTimeImmutable|null fin du blocage, null si la tentative est permise */
    public function blockedUntil(?string $email, ?string $ip): ?\DateTimeImmutable
    {
        $limit = $this->pair($email, $ip)->consume(0);

        return 0 === $limit->getRemainingTokens() ? $limit->getRetryAfter() : null;
    }

    public function recordFailure(?string $email, ?string $ip): void
    {
        $this->limiter('account', $this->max(), self::accountKey($email))->consume();
        $this->limiter('ip', $this->max(), self::ipKey($ip))->consume();
        $this->pair($email, $ip)->consume();
    }

    /** Connexion réussie : compteurs du compte remis à zéro (celui de l'IP reste : il protège les autres comptes). */
    public function recordSuccess(?string $email, ?string $ip): void
    {
        $this->limiter('account', $this->max(), self::accountKey($email))->reset();
        $this->pair($email, $ip)->reset();
    }

    private function pair(?string $email, ?string $ip): LimiterInterface
    {
        return $this->limiter('pair', 2 * $this->max(), self::accountKey($email).'|'.self::ipKey($ip));
    }

    private function exhausted(LimiterInterface $limiter): bool
    {
        return 0 === $limiter->consume(0)->getRemainingTokens();
    }

    private function limiter(string $scope, int $limit, string $key): LimiterInterface
    {
        $factory = new RateLimiterFactory([
            'id' => 'login_'.$scope,
            'policy' => 'sliding_window',
            'limit' => $limit,
            'interval' => \sprintf('%d minutes', $this->settings->int(SettingKey::AuthLoginWindowMin)),
        ], $this->storage);

        return $factory->create($key);
    }

    private function max(): int
    {
        return $this->settings->int(SettingKey::AuthLoginMaxAttempts);
    }

    /** Empreinte de l'adresse : aucune adresse en clair dans le cache. */
    private static function accountKey(?string $email): string
    {
        return hash('sha256', mb_strtolower(trim((string) $email)));
    }

    private static function ipKey(?string $ip): string
    {
        return $ip ?? 'unknown';
    }
}
