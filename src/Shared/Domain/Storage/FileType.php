<?php

declare(strict_types=1);

namespace App\Shared\Domain\Storage;

/** Types de fichiers acceptés (liste blanche, ADR-0008). Le type est déterminé par le contenu, jamais par le nom. */
enum FileType: string
{
    case Jpeg = 'image/jpeg';
    case Png = 'image/png';
    case Webp = 'image/webp';
    case Pdf = 'application/pdf';
    /** Variante produite par l'application (médiathèque BO10.7) : jamais acceptée en dépôt. */
    case Avif = 'image/avif';
    /** Archive produite par l'application (export RGPD) : jamais acceptée en dépôt. */
    case Zip = 'application/zip';

    public function extension(): string
    {
        return match ($this) {
            self::Jpeg => 'jpg',
            self::Png => 'png',
            self::Webp => 'webp',
            self::Pdf => 'pdf',
            self::Zip => 'zip',
            self::Avif => 'avif',
        };
    }

    public function isImage(): bool
    {
        return self::Pdf !== $this && self::Zip !== $this;
    }
}
