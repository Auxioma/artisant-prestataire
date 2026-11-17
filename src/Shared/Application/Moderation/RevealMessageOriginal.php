<?php

declare(strict_types=1);

namespace App\Shared\Application\Moderation;

use App\Shared\Application\Bus\Query;

/**
 * BO05 — lecture de l'original scellé d'un message signalé dont les coordonnées ont été masquées (ADR-0014). Chaque
 * lecture est journalisée (`message.original_read`). Traitée par Messaging ; réponse : string, ou null.
 */
final readonly class RevealMessageOriginal implements Query
{
    public function __construct(public string $adminId, public string $messageId, public ?string $ip)
    {
    }
}
