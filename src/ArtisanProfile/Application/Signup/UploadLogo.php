<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Signup;

use App\Shared\Application\Bus\Command;

/** A03.7 — logo ou photo du profil public (JPG, PNG, WebP ; ré-encodé, métadonnées retirées). */
final readonly class UploadLogo implements Command
{
    public function __construct(public string $userId, public string $path, public ?string $clientName)
    {
    }
}
