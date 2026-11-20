<?php

declare(strict_types=1);

namespace App\Notification\Application\Admin;

use App\Shared\Application\Bus\Command;

/**
 * BO11.1 — `save` (contenu + actif), `restore` (version antérieure, devient une nouvelle version), `test` (envoi à
 * l'adresse de l'administrateur, valeurs d'exemple).
 */
final readonly class TemplateAdminCommand implements Command
{
    public const array ACTIONS = ['save', 'restore', 'test'];

    /** @param array<string, mixed> $data */
    public function __construct(public string $action, public string $code, public ?string $adminId, public array $data = [])
    {
    }
}
