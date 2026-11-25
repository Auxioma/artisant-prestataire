<?php

declare(strict_types=1);

namespace App\Shared\Application\Bus;

/**
 * Marqueur : les implémentations exposent `__invoke(<Command>)` et sont enregistrées sur command.bus
 * par configuration (services.yaml), sans attribut framework dans la couche Application.
 */
interface CommandHandler
{
}
