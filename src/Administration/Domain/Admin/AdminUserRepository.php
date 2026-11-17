<?php

declare(strict_types=1);

namespace App\Administration\Domain\Admin;

use App\Shared\Domain\ValueObject\Email;

interface AdminUserRepository
{
    public function find(string $id): ?AdminUser;

    public function findByEmail(Email $email): ?AdminUser;

    public function findByInvitationTokenHash(string $tokenHash): ?AdminUser;

    public function add(AdminUser $admin): void;

    /** Super-admins actifs (l'équipe ne doit jamais en être privée). */
    public function countActiveSuperAdmins(): int;

    /** @return list<AdminUser> */
    public function all(): array;
}
