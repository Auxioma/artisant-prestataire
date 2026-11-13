<?php

declare(strict_types=1);

namespace App\Quote\Application\Revision;

use App\Shared\Application\Bus\Query;

/** PR04 en révision — totaux et contrôle RG-120 recalculés sans rien enregistrer (la version envoyée reste figée). */
final readonly class PreviewRevision implements Query
{
    /** @param array<string, mixed> $input */
    public function __construct(public string $userId, public string $requestId, public array $input)
    {
    }
}
