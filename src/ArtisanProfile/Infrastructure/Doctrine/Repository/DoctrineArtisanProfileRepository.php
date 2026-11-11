<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Infrastructure\Doctrine\Repository;

use App\ArtisanProfile\Domain\Profile\ArtisanProfile;
use App\ArtisanProfile\Domain\Profile\ArtisanProfileId;
use App\ArtisanProfile\Domain\Profile\ArtisanProfileRepository;
use App\Shared\Domain\ValueObject\Siret;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineArtisanProfileRepository implements ArtisanProfileRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function findByUserId(string $userId): ?ArtisanProfile
    {
        if (1 !== preg_match('/^[0-9a-f-]{36}$/', $userId)) {
            return null;
        }

        return $this->em->getRepository(ArtisanProfile::class)->findOneBy(['userId' => $userId]);
    }

    public function isSiretTaken(Siret $siret, ?ArtisanProfileId $except = null): bool
    {
        $qb = $this->em->createQueryBuilder()->select('count(p.id)')->from(ArtisanProfile::class, 'p')->where('p.siret = :siret')->setParameter('siret', $siret->value);
        if (null !== $except) {
            $qb->andWhere('p.id <> :id')->setParameter('id', $except->value);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    public function add(ArtisanProfile $profile): void
    {
        $this->em->persist($profile);
    }
}
