<?php

declare(strict_types=1);

namespace App\Content\Application\Port;

use App\Content\Application\Contact\ContactMessage;

/** Messages reçus par le formulaire de contact (traitement par le support ; tickets BO16 en V2). */
interface ContactMessages
{
    public function add(ContactMessage $message): void;

    public function find(string $id): ?ContactMessage;
}
