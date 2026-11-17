<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Infrastructure\Doctrine\Repository;

use App\ArtisanProfile\Domain\Profile\ArtisanProfile;
use App\ArtisanProfile\Domain\Profile\ArtisanProfileId;
use App\ArtisanProfile\Domain\Profile\ArtisanProfileRepository;
use App\ArtisanProfile\Domain\Profile\ArtisanStatus;
use App\Shared\Domain\ValueObject\Siret;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineArtisanProfileRepository implements ArtisanProfileRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function findByUserId(string $userId): ?ArtisanProfile
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $userId)) {
            return null;
        }

        return $this->em->getRepository(ArtisanProfile::class)->findOneBy(['userId' => $userId]);
    }

    public function find(ArtisanProfileId $id): ?ArtisanProfile
    {
        return $this->em->find(ArtisanProfile::class, $id);
    }

    public function isSiretTaken(Siret $siret, ?ArtisanProfileId $except = null): bool
    {
        $qb = $this->em->createQueryBuilder()->select('count(p.id)')->from(ArtisanProfile::class, 'p')->where('p.siret = :siret')->setParameter('siret', $siret->value);
        if (null !== $except) {
            $qb->andWhere('p.id <> :id')->setParameter('id', $except->value);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    public function pendingVerification(int $limit): array
    {
        /** @var list<ArtisanProfile> $profiles */
        $profiles = $this->em->createQueryBuilder()->select('p')->from(ArtisanProfile::class, 'p')
            ->where('p.status = :status')->setParameter('status', ArtisanStatus::PendingVerification)
            ->orderBy('p.submittedAt', 'ASC')->setMaxResults(max(1, $limit))
            ->getQuery()->getResult();

        return $profiles;
    }

    public function add(ArtisanProfile $profile): void
    {
        $this->em->persist($profile);
    }
}
