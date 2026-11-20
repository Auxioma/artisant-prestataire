<?php

declare(strict_types=1);

namespace App\Content\Application\Media;

use App\Shared\Application\Bus\Command;

final readonly class UpdateMedia implements Command
{
    public function __construct(public string $mediaId, public ?string $adminId, public string $title, public string $alt, public string $focal)
    {
    }
}
