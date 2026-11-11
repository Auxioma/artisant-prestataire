<?php

declare(strict_types=1);

namespace App\Identity\Application\Command;

use App\Shared\Application\Bus\Command;

final readonly class RecordCookieConsent implements Command
{
    /**
     * @param array<string, mixed> $choices
     */
    public function __construct(
        public string $visitorId,
        public array $choices,
        public string $source,
        public int $policyVersion,
        public ?string $userId = null,
        public ?string $ipHash = null,
    ) {
    }
}
