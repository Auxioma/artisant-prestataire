<?php

declare(strict_types=1);

namespace App\Notification\Application\Admin;

use App\Shared\Application\Bus\Query;

/**
 * BO11.1 « Aperçu » avec des valeurs d'exemple : contenu enregistré, ou contenu en cours d'édition (`content`).
 * Réponse : array{subject: ?string, html: ?string, text: ?string, in_app: ?string, error: ?string}.
 */
final readonly class PreviewTemplate implements Query
{
    /** @param array<string, mixed>|null $content */
    public function __construct(public string $code, public ?array $content = null)
    {
    }
}
