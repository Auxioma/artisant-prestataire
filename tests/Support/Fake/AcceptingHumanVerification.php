<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake;

use App\Identity\Application\Port\HumanVerification;
use App\Shared\Application\Integration\ExternalServiceUnavailable;

/** Captcha de test : jeton « humain » accepté, « robot » refusé, `$down` simule une panne du fournisseur. */
final class AcceptingHumanVerification implements HumanVerification
{
    public const string HUMAN = 'jeton-humain';

    public static bool $down = false;

    public function verify(string $token, ?string $remoteIp = null, ?string $expectedAction = null): bool
    {
        if (self::$down) {
            throw new ExternalServiceUnavailable('turnstile', 'arrêté (test)');
        }

        return self::HUMAN === $token;
    }
}
