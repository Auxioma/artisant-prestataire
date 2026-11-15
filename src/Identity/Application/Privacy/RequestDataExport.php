<?php

declare(strict_types=1);

namespace App\Identity\Application\Privacy;

use App\Shared\Application\Bus\Command;

/** C07.4 « Télécharger mes données » : archive JSON + PDF lisible, générée sous 24 h, lien valable 7 jours. */
final readonly class RequestDataExport implements Command
{
    public function __construct(public string $exportId, public string $userId, public ?string $ip = null)
    {
    }
}
