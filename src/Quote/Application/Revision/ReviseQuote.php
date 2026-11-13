<?php

declare(strict_types=1);

namespace App\Quote\Application\Revision;

use App\Shared\Application\Bus\Command;

/** RG-102 — révision d'un devis envoyé : nouvelle version (v2, v3…), gratuite, sans place supplémentaire. */
final readonly class ReviseQuote implements Command
{
    /** @param array<string, mixed> $input champs du formulaire PR04 */
    public function __construct(public string $userId, public string $requestId, public array $input)
    {
    }
}
