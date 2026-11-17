<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Job;

use App\Catalog\Domain\Category\CategoryId;
use App\Shared\Domain\ValueObject\Slug;

interface JobRepository
{
    public function get(JobId $id): Job;

    public function findBySlug(Slug $slug): ?Job;

    public function add(Job $job): void;

    public function nextPosition(CategoryId $category): int;
}
