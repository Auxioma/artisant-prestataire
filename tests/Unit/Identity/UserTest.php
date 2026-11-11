<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\Domain\User\Event\UserAnonymized;
use App\Identity\Domain\User\Event\UserEmailVerified;
use App\Identity\Domain\User\Event\UserPasswordChanged;
use App\Identity\Domain\User\Event\UserRegistered;
use App\Identity\Domain\User\IdentityRuleViolation;
use App\Identity\Domain\User\PersonName;
use App\Identity\Domain\User\User;
use App\Identity\Domain\User\UserId;
use App\Identity\Domain\User\UserKind;
use App\Identity\Domain\User\UserStatus;
use App\Shared\Domain\ValueObject\Email;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A01–A06, RGPD §11 : cycle de vie du compte. */
final class UserTest extends TestCase
{
    private const string ID = '0190f5e4-0000-7000-8000-000000000101';
    private const string HASH = '$argon2id$v=19$m=65536,t=4,p=1$ZmljdGlm$ZW1wcmVpbnRlLWZpY3RpdmU';

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-12T09:00:00+01:00');
    }

    private function user(UserKind $kind = UserKind::Client): User
    {
        return User::register(new UserId(self::ID), new Email('Camille.Exemple@Exemple.TEST'), $kind, self::HASH, new PersonName('Camille'), new PersonName('Exemple'), $this->now);
    }

    public function testRegistrationStartsPendingAndEventCarriesNoPersonalData(): void
    {
        $user = $this->user(UserKind::Artisan);
        $events = $user->releaseEvents();

        self::assertSame(UserStatus::PendingEmail, $user->status());
        self::assertSame('Camille.Exemple@exemple.test', $user->email()->value);
        self::assertFalse($user->isEmailVerified());
        self::assertCount(1, $events);
        self::assertInstanceOf(UserRegistered::class, $events[0]);
        self::assertSame(['userId' => self::ID, 'kind' => 'artisan', 'at' => $this->now], get_object_vars($events[0]));
    }

    public function testPlainPasswordIsRefused(): void
    {
        $this->expectException(IdentityRuleViolation::class);
        User::register(new UserId(self::ID), new Email('a@exemple.test'), UserKind::Client, 'motdepasse-en-clair', new PersonName('Al'), new PersonName('Bo'), $this->now);
    }

    public function testEmailVerificationActivatesOnce(): void
    {
        $user = $this->user();
        $user->releaseEvents();

        $user->verifyEmail($this->now);
        $user->verifyEmail($this->now->modify('+1 minute'));

        self::assertSame(UserStatus::Active, $user->status());
        self::assertCount(1, array_filter($user->releaseEvents(), static fn (object $e): bool => $e instanceof UserEmailVerified));
    }

    public function testPasswordChangeIsRecordedButRehashIsSilent(): void
    {
        $user = $this->user();
        $user->releaseEvents();
        $newHash = str_replace('ZW1w', 'bm91', self::HASH);

        $user->upgradePasswordHash($newHash);
        self::assertSame([], $user->releaseEvents());

        $user->changePassword(self::HASH, $this->now->modify('+1 day'));
        self::assertInstanceOf(UserPasswordChanged::class, $user->releaseEvents()[0] ?? null);
        self::assertEquals($this->now->modify('+1 day'), $user->passwordChangedAt());
    }

    public function testSuspensionBlocksLoginAndReinstatementRestoresPreviousState(): void
    {
        $user = $this->user();
        $user->suspend('Signalements répétés', $this->now);

        self::assertSame(UserStatus::Suspended, $user->status());
        self::assertFalse($user->status()->canLogIn());
        try {
            $user->recordLogin($this->now);
            self::fail('Connexion acceptée pour un compte suspendu');
        } catch (IdentityRuleViolation) {
        }

        $user->reinstate($this->now);
        self::assertSame(UserStatus::PendingEmail, $user->status(), 'e-mail jamais vérifié : retour en attente');

        $user->verifyEmail($this->now);
        $user->suspend('Contrôle', $this->now);
        $user->reinstate($this->now);
        self::assertSame(UserStatus::Active, $user->status());
    }

    public function testSuspensionRequiresAReason(): void
    {
        $this->expectException(IdentityRuleViolation::class);
        $this->user()->suspend('   ', $this->now);
    }

    public function testDeletionRequestIsCancellableButNotWhileSuspended(): void
    {
        $user = $this->user();
        $user->verifyEmail($this->now);
        $user->requestDeletion($this->now);
        self::assertSame(UserStatus::DeletionRequested, $user->status());
        self::assertFalse($user->status()->canLogIn());

        $user->cancelDeletion($this->now);
        self::assertSame(UserStatus::Active, $user->status());

        $user->suspend('Enquête en cours', $this->now);
        $this->expectException(IdentityRuleViolation::class);
        $user->requestDeletion($this->now);
    }

    public function testAnonymizationErasesPersonalDataAndIsTerminal(): void
    {
        $user = $this->user();
        $user->anonymize($this->now);

        self::assertSame(UserStatus::Anonymized, $user->status());
        self::assertSame('compte-'.self::ID.'@anonymise.invalid', $user->email()->value);
        self::assertSame(['Utilisateur', 'supprimé'], [$user->firstName(), $user->lastName()]);
        self::assertStringStartsWith('!', $user->passwordHash());
        self::assertInstanceOf(UserAnonymized::class, $user->releaseEvents()[1] ?? null);

        $this->expectException(IdentityRuleViolation::class);
        $user->changePassword(self::HASH, $this->now);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidNames(): iterable
    {
        yield 'vide' => ['  '];
        yield 'balise' => ['<script>'];
        yield 'chiffres' => ['R2D2'];
        yield 'une seule lettre' => ['A'];
        yield 'trop long' => [str_repeat('a', 51)];
        yield 'commence par un tiret' => ['-Anne'];
    }

    #[DataProvider('invalidNames')]
    public function testPersonNameRejectsInvalidValues(string $name): void
    {
        $this->expectException(IdentityRuleViolation::class);
        new PersonName($name);
    }

    public function testPersonNameAcceptsCompoundAndAccentedNames(): void
    {
        self::assertSame('Jean-Éloi d’Aubigné', new PersonName('  Jean-Éloi   d’Aubigné ')->value);
        self::assertSame("N'Diaye", new PersonName("N'Diaye")->value);
    }
}
