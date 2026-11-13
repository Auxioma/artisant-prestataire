<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Pro;

use App\Shared\Application\Bus\Command;

/** PR02 / PR03 « Ignorer » (motif facultatif) ; `$restore` la remet dans la liste. */
final readonly class IgnoreRequest implements Command
{
    public function __construct(public string $userId, public string $requestId, public ?string $reason = null, public bool $restore = false)
    {
    }
}
