<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine\Repository;

use App\Identity\Domain\User\IdentityRuleViolation;
use App\Identity\Domain\User\User;
use App\Identity\Domain\User\UserId;
use App\Identity\Domain\User\UserRepository;
use App\Identity\Domain\User\UserStatus;
use App\Shared\Domain\ValueObject\Email;
use App\Shared\Infrastructure\Persistence\DbalValue;
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

    public function dueForErasure(\DateTimeImmutable $before): array
    {
        return array_map(static fn (mixed $id): UserId => new UserId(DbalValue::string($id)), $this->em->getConnection()->fetchFirstColumn(
            "SELECT id FROM identity.user_account WHERE status = 'deletion_requested' AND deletion_requested_at <= :before ORDER BY deletion_requested_at",
            ['before' => $before->format(\DATE_ATOM)],
        ));
    }

    public function inactiveSince(\DateTimeImmutable $before): array
    {
        return array_map(static fn (mixed $id): UserId => new UserId(DbalValue::string($id)), $this->em->getConnection()->fetchFirstColumn(
            "SELECT u.id FROM identity.user_account u
              WHERE u.status IN ('active', 'pending_email') AND u.inactivity_notice_at IS NULL
                AND coalesce(u.last_login_at, u.created_at) <= :before
                AND NOT EXISTS (SELECT 1 FROM artisan.artisan_profile p JOIN billing.subscription s ON s.artisan_id = p.id
                                 WHERE p.user_id = u.id AND s.status IN ('active', 'past_due'))
              ORDER BY coalesce(u.last_login_at, u.created_at)",
            ['before' => $before->format(\DATE_ATOM)],
        ));
    }

    public function unansweredInactivityNotices(\DateTimeImmutable $before): array
    {
        return array_map(static fn (mixed $id): UserId => new UserId(DbalValue::string($id)), $this->em->getConnection()->fetchFirstColumn(
            "SELECT id FROM identity.user_account
              WHERE status IN ('active', 'pending_email') AND inactivity_notice_at <= :before
                AND (last_login_at IS NULL OR last_login_at < inactivity_notice_at)
              ORDER BY inactivity_notice_at",
            ['before' => $before->format(\DATE_ATOM)],
        ));
    }
}
