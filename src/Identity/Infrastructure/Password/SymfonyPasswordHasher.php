<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Password;

use App\Identity\Application\Port\PasswordHasher;
use App\Identity\Infrastructure\Security\SecurityUser;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;

/** Hacheur configuré pour SecurityUser (argon2id explicite, security.yaml) : identique à celui du formulaire de connexion. */
final readonly class SymfonyPasswordHasher implements PasswordHasher
{
    private PasswordHasherInterface $hasher;

    public function __construct(PasswordHasherFactoryInterface $factory)
    {
        $this->hasher = $factory->getPasswordHasher(SecurityUser::class);
    }

    public function hash(#[\SensitiveParameter] string $plain): string
    {
        return $this->hasher->hash($plain);
    }

    public function verify(string $hash, #[\SensitiveParameter] string $plain): bool
    {
        return '' !== $hash && $this->hasher->verify($hash, $plain);
    }

    public function needsRehash(string $hash): bool
    {
        return $this->hasher->needsRehash($hash);
    }
}
