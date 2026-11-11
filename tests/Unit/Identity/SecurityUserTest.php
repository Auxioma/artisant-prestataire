<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\Domain\User\UserKind;
use App\Identity\Domain\User\UserStatus;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Identity\Infrastructure\Security\UserChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;

/** Session : rôles, absence d'empreinte en session, fermeture des sessions après changement (ASVS 3.3.3). */
final class SecurityUserTest extends TestCase
{
    private const string HASH = '$argon2id$v=19$m=65536,t=4,p=1$ZmljdGlm$ZW1wcmVpbnRlLWZpY3RpdmU';

    private static function user(string $hash = self::HASH, UserStatus $status = UserStatus::Active, bool $verified = true, UserKind $kind = UserKind::Artisan): SecurityUser
    {
        return new SecurityUser('0190f5e4-0000-7000-8000-000000000101', 'camille@exemple.test', $hash, $kind, $status, $verified);
    }

    public function testRoles(): void
    {
        self::assertSame(['ROLE_USER', 'ROLE_ARTISAN', 'ROLE_VERIFIED'], self::user()->getRoles());
        self::assertSame(['ROLE_USER', 'ROLE_CLIENT'], self::user(status: UserStatus::PendingEmail, verified: false, kind: UserKind::Client)->getRoles(), 'RG-040 : pas de rôle vérifié');
    }

    public function testSessionHoldsNoPasswordHash(): void
    {
        $serialized = serialize(self::user());

        self::assertStringNotContainsString('argon2id', $serialized);
        $restored = unserialize($serialized);
        self::assertInstanceOf(SecurityUser::class, $restored);
        self::assertSame('', $restored->getPassword());
    }

    public function testRestoredSessionMatchesUnchangedAccountOnly(): void
    {
        $restored = unserialize(serialize(self::user()));
        self::assertInstanceOf(SecurityUser::class, $restored);

        self::assertTrue($restored->isEqualTo(self::user()));
        self::assertFalse($restored->isEqualTo(self::user(str_replace('ZW1w', 'bm91', self::HASH))), 'mot de passe changé');
        self::assertFalse($restored->isEqualTo(self::user(status: UserStatus::Suspended)), 'compte suspendu');
    }

    public function testCheckerRejectsInaccessibleAccounts(): void
    {
        $checker = new UserChecker();
        $checker->checkPreAuth(self::user(status: UserStatus::PendingEmail, verified: false));

        $refused = [];
        foreach ([UserStatus::Suspended, UserStatus::DeletionRequested, UserStatus::Anonymized] as $status) {
            try {
                $checker->checkPreAuth(self::user(status: $status));
            } catch (CustomUserMessageAccountStatusException) {
                $refused[] = $status;
            }
        }
        self::assertSame([UserStatus::Suspended, UserStatus::DeletionRequested, UserStatus::Anonymized], $refused);
    }
}
