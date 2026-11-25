<?php

declare(strict_types=1);

namespace App\Shared\Application\Bus;

interface CommandBus
{
    /**
     * Exécute la commande dans une transaction. Ne renvoie aucune entité.
     */
    public function dispatch(Command $command): void;
}
