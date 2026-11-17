<?php

declare(strict_types=1);

namespace App\Administration\Application\Command;

use App\Shared\Application\Bus\Command;

/**
 * BO13 : modification d'un paramètre administrable, motivée et journalisée.
 */
final readonly class ChangeSetting implements Command
{
    public function __construct(
        public string $key,
        public mixed $value,
        public string $adminId,
        public string $reason,
        public ?string $ip = null,
    ) {
    }
}
