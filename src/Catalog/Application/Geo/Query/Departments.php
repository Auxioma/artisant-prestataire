<?php

declare(strict_types=1);

namespace App\Catalog\Application\Geo\Query;

use App\Shared\Application\Bus\Query;

/** Départements du référentiel, par code. Réponse : array<string, string> (code => nom). */
final readonly class Departments implements Query
{
}
