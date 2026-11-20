<?php

declare(strict_types=1);

namespace App\Content\Application\Media;

final class MediaRejected extends \DomainException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}
