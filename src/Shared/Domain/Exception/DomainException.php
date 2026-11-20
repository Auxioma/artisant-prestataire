<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * Exception métier de base : violation d'une règle de gestion ou d'un invariant.
 */
abstract class DomainException extends \DomainException
{
}
