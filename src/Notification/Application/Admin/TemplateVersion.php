<?php

declare(strict_types=1);

namespace App\Notification\Application\Admin;

final readonly class TemplateVersion
{
    public function __construct(public int $version, public ?string $subject, public \DateTimeImmutable $createdAt, public ?string $createdBy)
    {
    }
}
