<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Edit;

use App\Shared\Application\Bus\Command;

/** PR08.5 — prestations et tarifs indicatifs (lignes sans intitulé ignorées). */
final readonly class UpdateServices implements Command
{
    /** @param list<array{title: string, price: string}> $services */
    public function __construct(public string $userId, public array $services)
    {
    }
}
