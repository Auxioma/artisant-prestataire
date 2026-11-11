<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Repository;

use App\Catalog\Domain\Category\Category;
use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Category\CategoryRepository;
use App\Catalog\Domain\Exception\CatalogRuleViolation;
use App\Shared\Domain\ValueObject\Slug;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineCategoryRepository implements CategoryRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function get(CategoryId $id): Category
    {
        return $this->em->find(Category::class, $id) ?? throw new CatalogRuleViolation('Catégorie introuvable.');
    }

    public function findBySlug(Slug $slug): ?Category
    {
        foreach ($this->em->getUnitOfWork()->getScheduledEntityInsertions() as $pending) {
            if ($pending instanceof Category && $pending->slug()->equals($slug)) {
                return $pending;
            }
        }

        return $this->em->getRepository(Category::class)->findOneBy(['slug' => $slug]);
    }

    public function add(Category $category): void
    {
        $this->em->persist($category);
    }

    public function nextPosition(?CategoryId $parent): int
    {
        $sql = null === $parent
            ? 'SELECT COALESCE(MAX(position) + 1, 0) FROM catalog.category WHERE parent_id IS NULL'
            : 'SELECT COALESCE(MAX(position) + 1, 0) FROM catalog.category WHERE parent_id = :parent';

        return DbalValue::int($this->em->getConnection()->fetchOne($sql, null === $parent ? [] : ['parent' => $parent->value]))
            + $this->pendingCount(Category::class, static fn (Category $c): bool => $c->parentId()?->value === $parent?->value);
    }

    /**
     * Entités persistées mais pas encore écrites (import en lot dans une même transaction).
     *
     * @param class-string<Category>   $class
     * @param \Closure(Category): bool $filter
     */
    private function pendingCount(string $class, \Closure $filter): int
    {
        $pending = array_filter($this->em->getUnitOfWork()->getScheduledEntityInsertions(), static fn (object $e): bool => $e instanceof $class && $filter($e));

        return \count($pending);
    }
}
