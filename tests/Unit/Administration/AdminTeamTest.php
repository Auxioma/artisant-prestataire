<?php

declare(strict_types=1);

namespace App\Tests\Unit\Administration;

use App\Administration\Application\Team\BackupCodes;
use App\Administration\Application\Team\InvitationToken;
use App\Administration\Domain\Admin\AdminAction;
use App\Administration\Domain\Admin\AdminModule;
use App\Administration\Domain\Admin\AdminRole;
use App\Administration\Domain\Admin\AdminRuleViolation;
use App\Administration\Domain\Admin\AdminStatus;
use App\Administration\Domain\Admin\AdminUser;
use App\Administration\Domain\Admin\Event\AdminActivated;
use App\Administration\Domain\Admin\Event\AdminInvited;
use App\Administration\Domain\Admin\Event\AdminRoleChanged;
use App\Shared\Domain\ValueObject\Email;
use PHPUnit\Framework\TestCase;

/** ADR-0013 / BO14 : permissions par rôle prédéfini, invitation, activation avec 2FA, codes de secours. */
final class AdminTeamTest extends TestCase
{
    private const string ID = '0190f5e4-0000-7000-8000-0000000a0001';
    private const string OTHER = '0190f5e4-0000-7000-8000-0000000a0002';
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-13 10:00:00');
    }

    private function invited(string $token = 'jeton'): AdminUser
    {
        return AdminUser::invite(self::ID, new Email('Lea.Exemple@Exemple.TEST'), '  Léa   Exemple ', AdminRole::Moderator, InvitationToken::hash($token), $this->now->modify('+72 hours'), self::OTHER, $this->now);
    }

    public function testSuperAdminHasEveryPermissionAndOnlyHeManagesTheTeam(): void
    {
        self::assertCount(\count(AdminModule::cases()) * \count(AdminAction::cases()), AdminRole::SuperAdmin->permissions());
        foreach (AdminRole::cases() as $role) {
            if (AdminRole::SuperAdmin !== $role) {
                self::assertFalse($role->allows('admin.team.view'), $role->value.' ne voit pas l’équipe');
                self::assertFalse($role->allows('admin.settings.edit'), $role->value.' ne modifie pas les paramètres');
            }
            self::assertTrue($role->allows('admin.dashboard.view'), 'tableau de bord pour tous');
        }
    }

    public function testPresetsFollowTheirMission(): void
    {
        self::assertTrue(AdminRole::Moderator->allows('admin.moderation.delete'));
        self::assertFalse(AdminRole::Moderator->allows('admin.plans.view'));
        self::assertTrue(AdminRole::Content->allows('admin.catalog.create'));
        self::assertFalse(AdminRole::Content->allows('admin.artisans.view'));
        self::assertTrue(AdminRole::Finance->allows('admin.plans.export'));
        self::assertFalse(AdminRole::Finance->allows('admin.clients.view'));
        foreach (AdminRole::ReadOnly->permissions() as $permission) {
            self::assertStringEndsWith('.view', $permission, 'lecture seule : aucune écriture ni export');
        }
        self::assertSame('ROLE_READ_ONLY', AdminRole::ReadOnly->securityRole());
    }

    public function testInvitationIsTimeLimitedAndTokenHashed(): void
    {
        $admin = $this->invited();

        self::assertSame(['lea.exemple@exemple.test', 'Léa Exemple', AdminStatus::Invited], [$admin->email()->value, $admin->name(), $admin->status()]);
        self::assertTrue($admin->isInvitationValid(InvitationToken::hash('jeton'), $this->now));
        self::assertFalse($admin->isInvitationValid(InvitationToken::hash('autre'), $this->now));
        self::assertFalse($admin->isInvitationValid(InvitationToken::hash('jeton'), $this->now->modify('+73 hours')), 'expirée');
        self::assertFalse($admin->canSignIn());
        self::assertInstanceOf(AdminInvited::class, $admin->releaseEvents()[0]);
    }

    public function testActivationRequiresAValidInvitationAndConsumesIt(): void
    {
        $admin = $this->invited();
        try {
            $admin->activate(InvitationToken::hash('faux'), 'empreinte', 'secret-chiffré', ['h1'], $this->now);
            self::fail('Activation avec un mauvais jeton');
        } catch (AdminRuleViolation) {
        }

        $admin->activate(InvitationToken::hash('jeton'), 'empreinte', 'secret-chiffré', ['h1', 'h2'], $this->now);

        self::assertTrue($admin->canSignIn());
        self::assertSame(2, $admin->remainingBackupCodes());
        self::assertFalse($admin->isInvitationValid(InvitationToken::hash('jeton'), $this->now), 'lien à usage unique');
        $events = $admin->releaseEvents();
        self::assertInstanceOf(AdminActivated::class, end($events));
    }

    public function testBackupCodesAreSingleUse(): void
    {
        $admin = $this->invited();
        $admin->activate(InvitationToken::hash('jeton'), 'empreinte', 'secret', ['h1', 'h2'], $this->now);

        self::assertTrue($admin->consumeBackupCode('h1', $this->now));
        self::assertFalse($admin->consumeBackupCode('h1', $this->now));
        self::assertSame(1, $admin->remainingBackupCodes());
    }

    public function testNobodyChangesOrDisablesTheirOwnAccount(): void
    {
        $admin = $this->invited();
        $admin->changeRole(AdminRole::Support, self::OTHER, $this->now);
        self::assertInstanceOf(AdminRoleChanged::class, $admin->releaseEvents()[1]);

        foreach ([static fn (AdminUser $a) => $a->changeRole(AdminRole::SuperAdmin, self::ID, new \DateTimeImmutable()), static fn (AdminUser $a) => $a->disable(self::ID, new \DateTimeImmutable())] as $selfAction) {
            try {
                $selfAction($admin);
                self::fail('Action sur son propre compte acceptée');
            } catch (AdminRuleViolation) {
            }
        }
    }

    public function testDisabledNeverActivatedAccountCannotBeEnabled(): void
    {
        $admin = $this->invited();
        $admin->disable(self::OTHER, $this->now);
        $this->expectExceptionMessage('renvoyez une invitation');
        $admin->enable(self::OTHER, $this->now);
    }

    public function testBackupCodesFormatAndKeyedHash(): void
    {
        $codes = new BackupCodes('poivre')->generate();

        self::assertCount(BackupCodes::COUNT, $codes);
        foreach ($codes as $code) {
            self::assertMatchesRegularExpression('/^[a-z2-9]{5}-[a-z2-9]{5}$/', $code);
        }
        self::assertSame(new BackupCodes('poivre')->hash('ABCDE-fghjk'), new BackupCodes('poivre')->hash('abcdefghjk'), 'tirets et casse ignorés');
        self::assertNotSame(new BackupCodes('poivre')->hash('abcdefghjk'), new BackupCodes('autre')->hash('abcdefghjk'), 'empreinte liée à la clé applicative');
        self::assertTrue(InvitationToken::isWellFormed(InvitationToken::generate()));
    }
}
