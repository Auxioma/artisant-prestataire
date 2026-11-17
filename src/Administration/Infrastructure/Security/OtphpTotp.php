<?php

declare(strict_types=1);

namespace App\Administration\Infrastructure\Security;

use App\Administration\Application\Port\Totp;
use OTPHP\TOTP as OtpTotp;
use Psr\Clock\ClockInterface;

/** RFC 6238 via spomky-labs/otphp ; tolérance d'un pas de 30 s de dérive d'horloge (ADR-0013). */
final readonly class OtphpTotp implements Totp
{
    public const string ISSUER = 'TrouveMoi back-office';
    private const int LEEWAY_SECONDS = 29;

    public function __construct(private ?ClockInterface $clock = null)
    {
    }

    public function generateSecret(): string
    {
        return OtpTotp::generate($this->clock)->getSecret();
    }

    public function provisioningUri(#[\SensitiveParameter] string $secret, string $account): string
    {
        if ('' === $secret || '' === $account) {
            throw new \InvalidArgumentException('Secret et compte requis.');
        }
        $totp = OtpTotp::createFromSecret($secret, $this->clock);
        $totp->setLabel($account);
        $totp->setIssuer(self::ISSUER);

        return $totp->getProvisioningUri();
    }

    public function verify(#[\SensitiveParameter] string $secret, string $code): bool
    {
        $code = (string) preg_replace('/\s+/', '', $code);
        if (1 !== preg_match('/^\d{6}$/', $code) || '' === $secret) {
            return false;
        }

        return OtpTotp::createFromSecret($secret, $this->clock)->verify($code, null, self::LEEWAY_SECONDS);
    }
}
