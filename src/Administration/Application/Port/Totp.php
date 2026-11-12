<?php

declare(strict_types=1);

namespace App\Administration\Application\Port;

/** TOTP RFC 6238 (6 chiffres, 30 s, SHA-1 : compatible avec les applications d'authentification courantes). */
interface Totp
{
    public function generateSecret(): string;

    /** URI otpauth:// affichée en QR code lors de la configuration. */
    public function provisioningUri(#[\SensitiveParameter] string $secret, string $account): string;

    public function verify(#[\SensitiveParameter] string $secret, string $code): bool;
}
