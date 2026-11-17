<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Repository;

use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Exception\CatalogRuleViolation;
use App\Catalog\Domain\Job\Job;
use App\Catalog\Domain\Job\JobId;
use App\Catalog\Domain\Job\JobRepository;
use App\Shared\Domain\ValueObject\Slug;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineJobRepository implements JobRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function get(JobId $id): Job
    {
        return $this->em->find(Job::class, $id) ?? throw new CatalogRuleViolation('Métier introuvable.');
    }

    public function findBySlug(Slug $slug): ?Job
    {
        foreach ($this->em->getUnitOfWork()->getScheduledEntityInsertions() as $pending) {
            if ($pending instanceof Job && $pending->slug()->equals($slug)) {
                return $pending;
            }
        }

        return $this->em->getRepository(Job::class)->findOneBy(['slug' => $slug]);
    }

    public function add(Job $job): void
    {
        $this->em->persist($job);
    }

    public function nextPosition(CategoryId $category): int
    {
        $stored = DbalValue::int($this->em->getConnection()->fetchOne('SELECT COALESCE(MAX(position) + 1, 0) FROM catalog.job WHERE category_id = :c', ['c' => $category->value]));
        $pending = array_filter(
            $this->em->getUnitOfWork()->getScheduledEntityInsertions(),
            static fn (object $e): bool => $e instanceof Job && $e->categoryId()->equals($category),
        );

        return $stored + \count($pending);
    }
}
