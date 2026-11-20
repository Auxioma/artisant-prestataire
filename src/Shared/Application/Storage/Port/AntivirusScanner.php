<?php

declare(strict_types=1);

namespace App\Shared\Application\Storage\Port;

use App\Shared\Application\Integration\ExternalServiceUnavailable;

interface AntivirusScanner
{
    /**
     * @param resource $stream
     *
     * @return string|null nom de la signature détectée, null si le fichier est sain
     *
     * @throws ExternalServiceUnavailable démon injoignable ou réponse illisible (le message sera retraité)
     */
    public function scan($stream): ?string;
}
