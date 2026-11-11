<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Exception;

use App\Shared\Domain\Exception\DomainException;

/** Violation d'une règle du référentiel (BO06). */
final class CatalogRuleViolation extends DomainException
{
}
