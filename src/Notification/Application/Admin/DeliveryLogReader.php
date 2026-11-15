<?php

declare(strict_types=1);

namespace App\Notification\Application\Admin;

/** Lecture du journal d'envoi (adresses masquées ; recherche par empreinte de l'adresse exacte). */
interface DeliveryLogReader
{
    public function page(DeliveryLogQuery $query): DeliveryLogPage;
}
