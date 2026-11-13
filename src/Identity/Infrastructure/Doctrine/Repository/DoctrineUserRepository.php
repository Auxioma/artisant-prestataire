<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine\Repository;

use App\Identity\Domain\User\IdentityRuleViolation;
use App\Identity\Domain\User\User;
use App\Identity\Domain\User\UserId;
use App\Identity\Domain\User\UserRepository;
use App\Identity\Domain\User\UserStatus;
use App\Shared\Domain\ValueObject\Email;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineUserRepository implements UserRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function get(UserId $id): User
    {
        return $this->find($id) ?? throw new IdentityRuleViolation('Compte introuvable.');
    }

    public function find(UserId $id): ?User
    {
        return $this->em->find(User::class, $id);
    }

    public function findByEmail(Email $email): ?User
    {
        $canonical = $email->canonical();
        foreach ($this->em->getUnitOfWork()->getScheduledEntityInsertions() as $pending) {
            if ($pending instanceof User && $pending->email()->canonical() === $canonical) {
                return $pending;
            }
        }

        $user = $this->em->createQueryBuilder()
            ->select('u')->from(User::class, 'u')
            ->where('u.emailCanonical = :email')->andWhere('u.status <> :anonymized')
            ->setParameter('email', $canonical)->setParameter('anonymized', UserStatus::Anonymized->value)
            ->getQuery()->getOneOrNullResult();

        return $user instanceof User ? $user : null;
    }

    public function add(User $user): void
    {
        $this->em->persist($user);
    }

    public function recordLogin(UserId $id, \DateTimeImmutable $at): void
    {
        $this->em->getConnection()->executeStatement(
            'UPDATE identity.user_account SET last_login_at = GREATEST(coalesce(last_login_at, :at), :at) WHERE id = :id',
            ['at' => $at->format('Y-m-d H:i:sP'), 'id' => $id->value],
        );
    }
}
