<?php

declare(strict_types=1);

namespace App\Content\Application\Newsletter;

use App\Shared\Application\Bus\Command;

/** Pied de page « Restez informé » : inscription à la newsletter (l'envoi relève de l'outil retenu, Q25). */
final readonly class SubscribeToNewsletter implements Command
{
    public function __construct(public string $id, public string $email, public string $source, public ?string $ipHash = null)
    {
    }
}
