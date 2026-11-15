<?php

declare(strict_types=1);

namespace App\Content\Application\Media;

use App\Shared\Application\Bus\Command;

/** BO10.7 — dépôt d'une image : texte alternatif obligatoire ; variantes WebP / AVIF générées après l'analyse antivirus. */
final readonly class UploadMedia implements Command
{
    public function __construct(public string $mediaId, public ?string $adminId, public string $path, public ?string $clientName, public string $title, public string $alt, public string $focal = 'cc')
    {
    }
}
