<?php

declare(strict_types=1);

namespace App\Content\Application\Port;

use App\Content\Application\Contact\ContactMessage;

interface ContactMailer
{
    /** E-90 — accusé de réception à l'expéditeur. */
    public function acknowledge(ContactMessage $message, int $replyHours): void;

    /** I-03 — alerte à la boîte du support (routage selon le motif). */
    public function alertSupport(ContactMessage $message): void;
}
