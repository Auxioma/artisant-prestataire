<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\Application\Password\PasswordPolicy;
use App\Identity\Application\Password\WeakPassword;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Domain\ValueObject\Email;
use App\Tests\Support\Fake\RecordingCompromisedPasswordChecker;
use App\Tests\Support\InMemorySettingsProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** ASVS 2.1 : longueur paramétrable (≥ 12), 128 caractères acceptés, aucune règle de composition, HIBP. */
final class PasswordPolicyTest extends TestCase
{
    private InMemorySettingsProvider $settings;
    private RecordingCompromisedPasswordChecker $checker;

    protected function setUp(): void
    {
        $this->settings = new InMemorySettingsProvider();
    }

    private function policy(bool $compromised = false): PasswordPolicy
    {
        $this->checker = new RecordingCompromisedPasswordChecker($compromised);

        return new PasswordPolicy($this->settings, $this->checker);
    }

    private function reason(string $password, ?Email $email = null, bool $compromised = false): ?string
    {
        try {
            $this->policy($compromised)->assertAcceptable($password, $email);

            return null;
        } catch (WeakPassword $e) {
            return $e->reason;
        }
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function passwords(): iterable
    {
        yield '11 caractères' => ['abcdefghijk', WeakPassword::TOO_SHORT];
        yield '12 caractères, sans règle de composition' => ['abcdefghijkl', null];
        yield 'phrase de passe Unicode (longueur en caractères)' => ['été à Ouagadou', null];
        yield '11 caractères multioctets' => ['ééééééééééé', WeakPassword::TOO_SHORT];
        yield '128 caractères' => [str_repeat('x', 128), null];
        yield '129 caractères' => [str_repeat('x', 129), WeakPassword::TOO_LONG];
        yield 'espaces conservés (pas de troncature)' => ['   abcdefghi   ', null];
    }

    #[DataProvider('passwords')]
    public function testLengthRules(string $password, ?string $expected): void
    {
        self::assertSame($expected, $this->reason($password));
    }

    public function testMinimumLengthComesFromSettings(): void
    {
        $this->settings->set(SettingKey::AuthPasswordMinLength, 16);

        self::assertSame(WeakPassword::TOO_SHORT, $this->reason('abcdefghijklmno'));
        self::assertSame(16, $this->policy()->minLength());
        self::assertNull($this->reason('abcdefghijklmnop'));
    }

    public function testPasswordContainingTheEmailIsRefused(): void
    {
        $email = new Email('camille.exemple@exemple.test');

        self::assertSame(WeakPassword::CONTAINS_EMAIL, $this->reason('MonCAMILLE.EXEMPLE2026', $email));
        self::assertNull($this->reason('cheval-agrafe-batterie', $email));
    }

    public function testCompromisedPasswordIsRefusedAndCheckedLast(): void
    {
        self::assertSame(WeakPassword::COMPROMISED, $this->reason('motdepasse123', null, true));
        self::assertSame(WeakPassword::TOO_SHORT, $this->reason('court', null, true));
        self::assertSame([], $this->checker->checked, 'aucun appel HIBP pour un mot de passe déjà refusé localement');
        $this->reason('motdepasse123', null, true);
        self::assertSame(['motdepasse123'], $this->checker->checked, 'contrôle HIBP effectué en dernier');
    }
}
