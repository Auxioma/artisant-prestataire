<?php

declare(strict_types=1);

namespace App\Notification\Application;

/** Point d'entrée des autres modules (CDC §6). Ne lève jamais d'exception : un échec est consigné au journal d'envoi. */
interface Notifier
{
    public function notify(Notification $notification): void;
}
