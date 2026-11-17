<?php

declare(strict_types=1);

namespace App\Notification\Application\Port;

use App\Notification\Application\Template;

interface TemplateStore
{
    public function find(string $code): ?Template;
}
