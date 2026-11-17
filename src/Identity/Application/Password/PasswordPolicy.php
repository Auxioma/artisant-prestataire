<?php

declare(strict_types=1);

namespace App\Identity\Application\Password;

use App\Identity\Application\Port\CompromisedPasswordChecker;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Domain\ValueObject\Email;

/**
 * ASVS 2.1 (cadrage §5.1) : longueur minimale paramétrable (≥ 12, `auth.password_min_length`), 128 caractères acceptés,
 * aucune règle de composition, tout Unicode admis, aucune troncature ; mots de passe compromis refusés (HIBP).
 * Appliquée identiquement à l'inscription, au changement et à la réinitialisation.
 */
final readonly class PasswordPolicy
{
    public const int MAX_LENGTH = 128;
    private const int ABSOLUTE_MIN_LENGTH = 12;
    private const int EMAIL_LOCAL_PART_MIN = 4;

    public function __construct(private SettingsProvider $settings, private CompromisedPasswordChecker $compromised)
    {
    }

    public function minLength(): int
    {
        return max(self::ABSOLUTE_MIN_LENGTH, $this->settings->int(SettingKey::AuthPasswordMinLength));
    }

    /** @throws WeakPassword */
    public function assertAcceptable(#[\SensitiveParameter] string $plain, ?Email $email = null): void
    {
        $min = $this->minLength();
        $length = mb_strlen($plain);
        if ($length < $min) {
            throw new WeakPassword(WeakPassword::TOO_SHORT, $min);
        }
        if ($length > self::MAX_LENGTH) {
            throw new WeakPassword(WeakPassword::TOO_LONG, $min);
        }
        if (null !== $email) {
            $local = mb_strtolower(substr($email->value, 0, (int) strrpos($email->value, '@')));
            if (mb_strlen($local) >= self::EMAIL_LOCAL_PART_MIN && str_contains(mb_strtolower($plain), $local)) {
                throw new WeakPassword(WeakPassword::CONTAINS_EMAIL, $min);
            }
        }
        if ($this->compromised->isCompromised($plain)) {
            throw new WeakPassword(WeakPassword::COMPROMISED, $min);
        }
    }
}
