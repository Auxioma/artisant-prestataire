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
    /** Export CSV produit par l'application (exports planifiés BO15.2) : jamais accepté en dépôt. */
    case Csv = 'text/csv';

    public function extension(): string
    {
        return match ($this) {
            self::Jpeg => 'jpg',
            self::Png => 'png',
            self::Webp => 'webp',
            self::Pdf => 'pdf',
            self::Zip => 'zip',
            self::Avif => 'avif',
            self::Csv => 'csv',
        };
    }

    public function isImage(): bool
    {
        return !\in_array($this, [self::Pdf, self::Zip, self::Csv], true);
    }
}
