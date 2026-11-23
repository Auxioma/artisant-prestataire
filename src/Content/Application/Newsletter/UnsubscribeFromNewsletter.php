<?php

declare(strict_types=1);

namespace App\Content\Application\Newsletter;

use App\Shared\Application\Bus\Command;

/** Désinscription en un clic (lien personnel joint à chaque envoi). */
final readonly class UnsubscribeFromNewsletter implements Command
{
    public function __construct(public string $token)
    {
    }
}
