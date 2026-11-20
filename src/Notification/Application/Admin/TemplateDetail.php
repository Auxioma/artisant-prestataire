<?php

declare(strict_types=1);

namespace App\Notification\Application\Admin;

/** BO11.1 — modèle d'un déclencheur (annexe F) tel qu'édité en back-office. */
final readonly class TemplateDetail
{
    /**
     * @param list<string> $channels
     * @param list<string> $variables
     */
    public function __construct(
        public string $code,
        public string $label,
        public string $recipient,
        public array $channels,
        public array $variables,
        public ?string $subject,
        public ?string $preheader,
        public ?string $bodyHtml,
        public ?string $bodyText,
        public ?string $inAppBody,
        public bool $active,
        public int $version,
        public bool $customized,
        public \DateTimeImmutable $updatedAt,
    ) {
    }

    public function has(string $channel): bool
    {
        return \in_array($channel, $this->channels, true);
    }
}
