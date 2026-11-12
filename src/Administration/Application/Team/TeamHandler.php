<?php

declare(strict_types=1);

namespace App\Administration\Application\Team;

use App\Administration\Application\Port\AdminMailer;
use App\Administration\Application\Port\AdminPasswordHasher;
use App\Administration\Application\Port\Totp;
use App\Administration\Domain\Admin\AdminRole;
use App\Administration\Domain\Admin\AdminRuleViolation;
use App\Administration\Domain\Admin\AdminStatus;
use App\Administration\Domain\Admin\AdminUser;
use App\Administration\Domain\Admin\AdminUserRepository;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Bus\EventBus;
use App\Shared\Application\Security\SecretBox;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;
use App\Shared\Domain\ValueObject\Email;

/**
 * BO14.1 — cycle de vie des comptes d'administration. Toutes les actions sont journalisées (AuditLog chaîné) ; l'équipe
 * ne peut jamais perdre son dernier super-admin actif.
 */
final readonly class TeamHandler implements CommandHandler
{
    public function __construct(
        private AdminUserRepository $admins,
        private AdminPasswordHasher $hasher,
        private Totp $totp,
        private SecretBox $secrets,
        private BackupCodes $backupCodes,
        private AdminMailer $mailer,
        private SettingsProvider $settings,
        private EventBus $events,
        private AuditTrail $audit,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    public function __invoke(InviteAdmin|RenewAdminInvitation|AcceptAdminInvitation|ChangeAdminRole|DisableAdmin|EnableAdmin|ConsumeAdminBackupCode|RecordAdminLogin $command): void
    {
        $now = $this->clock->now();
        match (true) {
            $command instanceof InviteAdmin => $this->invite($command, $now),
            $command instanceof RenewAdminInvitation => $this->renew($command, $now),
            $command instanceof AcceptAdminInvitation => $this->accept($command, $now),
            $command instanceof ChangeAdminRole => $this->changeRole($command, $now),
            $command instanceof DisableAdmin => $this->disable($command, $now),
            $command instanceof EnableAdmin => $this->enable($command, $now),
            $command instanceof ConsumeAdminBackupCode => $this->consumeBackupCode($command, $now),
            default => $this->recordLogin($command, $now),
        };
    }

    private function invite(InviteAdmin $command, \DateTimeImmutable $now): void
    {
        $email = new Email($command->email);
        if (null !== $this->admins->findByEmail($email)) {
            throw new TeamRejected(TeamRejected::EMAIL_TAKEN, 'Un administrateur utilise déjà cette adresse.');
        }
        $role = AdminRole::tryFrom($command->role) ?? throw new AdminRuleViolation('Rôle inconnu.');
        $ttl = $this->settings->int(SettingKey::AdminInvitationTtlHours);
        $admin = AdminUser::invite($command->adminId, $email, $command->name, $role, InvitationToken::hash($command->token), $now->modify(\sprintf('+%d hours', $ttl)), $command->invitedBy, $now);
        $this->admins->add($admin);
        $this->publish($admin);
        $this->mailer->sendInvitation($email->value, $admin->name(), $role->label(), $command->token, $ttl, $admin->id());
        $this->log($command->invitedBy, 'admin.invited', $admin, $now, ['role' => $role->value]);
    }

    private function renew(RenewAdminInvitation $command, \DateTimeImmutable $now): void
    {
        $admin = $this->admin($command->adminId);
        $ttl = $this->settings->int(SettingKey::AdminInvitationTtlHours);
        $admin->renewInvitation(InvitationToken::hash($command->token), $now->modify(\sprintf('+%d hours', $ttl)), $command->by, $now);
        $this->publish($admin);
        $this->mailer->sendInvitation($admin->email()->value, $admin->name(), $admin->role()->label(), $command->token, $ttl, $admin->id());
        $this->log($command->by, 'admin.invitation_renewed', $admin, $now);
    }

    private function accept(AcceptAdminInvitation $command, \DateTimeImmutable $now): void
    {
        $admin = InvitationToken::isWellFormed($command->token) ? $this->admins->findByInvitationTokenHash(InvitationToken::hash($command->token)) : null;
        if (null === $admin || !$admin->isInvitationValid(InvitationToken::hash($command->token), $now)) {
            throw new TeamRejected(TeamRejected::INVITATION_INVALID, 'Ce lien d’invitation n’est plus valide. Demandez-en un nouveau à un super-admin.');
        }
        if (!$this->totp->verify($command->totpSecret, $command->totpCode)) {
            throw new TeamRejected(TeamRejected::TOTP_INVALID, 'Code incorrect : saisissez le code à 6 chiffres affiché par votre application.');
        }
        $admin->activate(
            InvitationToken::hash($command->token),
            $this->hasher->hash($command->password),
            $this->secrets->seal($command->totpSecret),
            array_map($this->backupCodes->hash(...), $command->backupCodes),
            $now,
        );
        $this->publish($admin);
        $this->log($admin->id(), 'admin.activated', $admin, $now);
    }

    private function changeRole(ChangeAdminRole $command, \DateTimeImmutable $now): void
    {
        $admin = $this->admin($command->adminId);
        $role = AdminRole::tryFrom($command->role) ?? throw new AdminRuleViolation('Rôle inconnu.');
        $from = $admin->role();
        if (AdminRole::SuperAdmin === $from && AdminRole::SuperAdmin !== $role) {
            $this->assertNotLastSuperAdmin($admin);
        }
        $admin->changeRole($role, $command->by, $now);
        $this->publish($admin);
        $this->log($command->by, 'admin.role_changed', $admin, $now, ['from' => $from->value, 'to' => $role->value]);
    }

    private function disable(DisableAdmin $command, \DateTimeImmutable $now): void
    {
        $admin = $this->admin($command->adminId);
        if (AdminRole::SuperAdmin === $admin->role()) {
            $this->assertNotLastSuperAdmin($admin);
        }
        $admin->disable($command->by, $now);
        $this->publish($admin);
        $this->log($command->by, 'admin.disabled', $admin, $now);
    }

    private function enable(EnableAdmin $command, \DateTimeImmutable $now): void
    {
        $admin = $this->admin($command->adminId);
        $admin->enable($command->by, $now);
        $this->publish($admin);
        $this->log($command->by, 'admin.enabled', $admin, $now);
    }

    private function consumeBackupCode(ConsumeAdminBackupCode $command, \DateTimeImmutable $now): void
    {
        $admin = $this->admin($command->adminId);
        if ($admin->consumeBackupCode($this->backupCodes->hash($command->code), $now)) {
            $this->log($admin->id(), 'admin.backup_code_used', $admin, $now, ['remaining' => $admin->remainingBackupCodes()]);
        }
    }

    private function recordLogin(RecordAdminLogin $command, \DateTimeImmutable $now): void
    {
        $admin = $this->admin($command->adminId);
        $admin->recordLogin($now);
        $this->log($admin->id(), 'admin.login', $admin, $now, [], $command->ip);
    }

    private function assertNotLastSuperAdmin(AdminUser $admin): void
    {
        if (AdminStatus::Active === $admin->status() && $this->admins->countActiveSuperAdmins() <= 1) {
            throw new TeamRejected(TeamRejected::LAST_SUPER_ADMIN, 'L’équipe doit garder au moins un super-admin actif.');
        }
    }

    private function admin(string $id): AdminUser
    {
        return (1 === preg_match('/^[0-9a-f-]{36}$/', $id) ? $this->admins->find($id) : null) ?? throw new TeamRejected(TeamRejected::UNKNOWN, 'Administrateur introuvable.');
    }

    private function publish(AdminUser $admin): void
    {
        $this->events->publish(...$admin->releaseEvents());
    }

    /** @param array<string, scalar> $context */
    private function log(?string $actor, string $action, AdminUser $admin, \DateTimeImmutable $now, array $context = [], ?string $ip = null): void
    {
        $this->audit->record(new AuditRecord(
            id: $this->ids->next(),
            occurredAt: $now,
            actorType: null === $actor ? 'system' : 'admin',
            actorId: $actor,
            action: $action,
            subjectType: 'admin_user',
            subjectId: $admin->id(),
            ip: $ip,
            context: $context,
        ));
    }
}
