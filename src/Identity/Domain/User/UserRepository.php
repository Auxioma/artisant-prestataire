<?php

declare(strict_types=1);

namespace App\Identity\Domain\User;

use App\Shared\Domain\ValueObject\Email;

interface UserRepository
{
    /** @throws IdentityRuleViolation */
    public function get(UserId $id): User;

    public function find(UserId $id): ?User;

    /** Compte non anonymisé portant cet e-mail (forme canonique). */
    public function findByEmail(Email $email): ?User;

    public function add(User $user): void;
}
