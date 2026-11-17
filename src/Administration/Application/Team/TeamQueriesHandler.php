<?php

declare(strict_types=1);

namespace App\Administration\Application\Team;

use App\Administration\Domain\Admin\AdminUser;
use App\Administration\Domain\Admin\AdminUserRepository;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Domain\Time\Clock;

final readonly class TeamQueriesHandler implements QueryHandler
{
    public function __construct(private AdminUserRepository $admins, private Clock $clock)
    {
    }

    /** @return list<AdminMemberView>|AdminMemberView|null */
    public function __invoke(TeamMembers|InvitationDetails $query): array|AdminMemberView|null
    {
        if ($query instanceof TeamMembers) {
            return array_map(self::view(...), $this->admins->all());
        }
        if (!InvitationToken::isWellFormed($query->token)) {
            return null;
        }
        $hash = InvitationToken::hash($query->token);
        $admin = $this->admins->findByInvitationTokenHash($hash);

        return null !== $admin && $admin->isInvitationValid($hash, $this->clock->now()) ? self::view($admin) : null;
    }

    private static function view(AdminUser $admin): AdminMemberView
    {
        return new AdminMemberView(
            $admin->id(),
            $admin->name(),
            $admin->email()->value,
            $admin->role()->value,
            $admin->role()->label(),
            $admin->status()->value,
            $admin->status()->label(),
            null !== $admin->sealedTotpSecret(),
            $admin->remainingBackupCodes(),
            $admin->lastLoginAt(),
            $admin->invitationExpiresAt(),
        );
    }
}
