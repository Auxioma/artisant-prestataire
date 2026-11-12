<?php

declare(strict_types=1);

namespace App\Administration\Infrastructure\Doctrine\Repository;

use App\Administration\Domain\Admin\AdminRole;
use App\Administration\Domain\Admin\AdminStatus;
use App\Administration\Domain\Admin\AdminUser;
use App\Administration\Domain\Admin\AdminUserRepository;
use App\Shared\Domain\ValueObject\Email;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineAdminUserRepository implements AdminUserRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function find(string $id): ?AdminUser
    {
        return $this->em->find(AdminUser::class, $id);
    }

    public function findByEmail(Email $email): ?AdminUser
    {
        return $this->em->getRepository(AdminUser::class)->findOneBy(['email' => $email->canonical()]);
    }

    public function findByInvitationTokenHash(string $tokenHash): ?AdminUser
    {
        return $this->em->getRepository(AdminUser::class)->findOneBy(['invitationTokenHash' => $tokenHash]);
    }

    public function add(AdminUser $admin): void
    {
        $this->em->persist($admin);
    }

    public function countActiveSuperAdmins(): int
    {
        return (int) $this->em->createQueryBuilder()->select('count(a.id)')->from(AdminUser::class, 'a')
            ->where('a.role = :role')->andWhere('a.status = :status')
            ->setParameter('role', AdminRole::SuperAdmin)->setParameter('status', AdminStatus::Active)
            ->getQuery()->getSingleScalarResult();
    }

    public function all(): array
    {
        /** @var list<AdminUser> $admins */
        $admins = $this->em->getRepository(AdminUser::class)->findBy([], ['status' => 'ASC', 'name' => 'ASC']);

        return $admins;
    }
}
