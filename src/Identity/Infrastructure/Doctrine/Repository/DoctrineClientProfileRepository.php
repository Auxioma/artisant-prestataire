<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine\Repository;

use App\Identity\Domain\Client\ClientProfile;
use App\Identity\Domain\Client\ClientProfileRepository;
use App\Identity\Domain\User\UserId;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineClientProfileRepository implements ClientProfileRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function find(UserId $userId): ?ClientProfile
    {
        return $this->em->find(ClientProfile::class, $userId);
    }

    public function add(ClientProfile $profile): void
    {
        $this->em->persist($profile);
    }
}
