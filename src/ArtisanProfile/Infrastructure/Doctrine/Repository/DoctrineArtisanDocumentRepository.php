<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Infrastructure\Doctrine\Repository;

use App\ArtisanProfile\Domain\Document\ArtisanDocument;
use App\ArtisanProfile\Domain\Document\ArtisanDocumentRepository;
use App\ArtisanProfile\Domain\Document\DocumentStatus;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineArtisanDocumentRepository implements ArtisanDocumentRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function add(ArtisanDocument $document): void
    {
        $this->em->persist($document);
    }

    public function find(string $id): ?ArtisanDocument
    {
        return 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id) ? $this->em->find(ArtisanDocument::class, $id) : null;
    }

    public function findByFileId(string $fileId): ?ArtisanDocument
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $fileId)) {
            return null;
        }

        return $this->em->getRepository(ArtisanDocument::class)->findOneBy(['fileId' => $fileId]);
    }

    /** @return list<ArtisanDocument> */
    public function currentOf(string $artisanId): array
    {
        /** @var list<ArtisanDocument> $documents */
        $documents = $this->em->createQueryBuilder()
            ->select('d')->from(ArtisanDocument::class, 'd')
            ->where('d.artisanId = :artisan')->andWhere('d.status <> :shredded')
            ->setParameter('artisan', $artisanId)->setParameter('shredded', DocumentStatus::Shredded)
            ->orderBy('d.uploadedAt', 'DESC')
            ->getQuery()->getResult();

        return $documents;
    }
}
