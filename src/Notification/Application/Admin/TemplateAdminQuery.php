<?php

declare(strict_types=1);

namespace App\Notification\Application\Admin;

use App\Shared\Application\Bus\Query;

/** Réponse : list<TemplateDetail> (sans `code`) ou array{template: TemplateDetail, versions: list<TemplateVersion>}|null. */
final readonly class TemplateAdminQuery implements Query
{
    public function __construct(public ?string $code = null)
    {
    }
}
