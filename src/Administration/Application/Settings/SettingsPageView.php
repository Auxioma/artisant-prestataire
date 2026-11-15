<?php

declare(strict_types=1);

namespace App\Administration\Application\Settings;

final readonly class SettingsPageView
{
    /**
     * @param list<array{key: string, label: string, type: string, value: mixed, default: mixed, min: ?int, max: ?int, choices: ?array<mixed>, proposed: bool}> $fields
     * @param list<array{at: \DateTimeImmutable, admin: ?string, key: string, before: mixed, after: mixed}>                                                     $history
     */
    public function __construct(public string $rubric, public string $label, public array $fields, public array $history)
    {
    }
}
