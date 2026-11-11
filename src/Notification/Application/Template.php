<?php

declare(strict_types=1);

namespace App\Notification\Application;

/** Modèle tel que publié (BO11.1). */
final readonly class Template
{
    /**
     * @param list<string> $channels
     */
    public function __construct(
        public string $code,
        public array $channels,
        public bool $active,
        public int $version,
        public ?string $subject,
        public ?string $preheader,
        public ?string $bodyHtml,
        public ?string $bodyText,
        public ?string $inAppBody,
    ) {
    }

    public function has(string $channel): bool
    {
        return \in_array($channel, $this->channels, true);
    }
}
