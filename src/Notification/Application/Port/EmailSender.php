<?php

declare(strict_types=1);

namespace App\Notification\Application\Port;

use App\Notification\Application\RenderedEmail;

interface EmailSender
{
    /**
     * @return string|null identifiant attribué par le fournisseur (suivi des rebonds)
     *
     * @throws \RuntimeException envoi refusé ou fournisseur injoignable
     */
    public function send(string $to, RenderedEmail $email, string $code, string $deliveryId): ?string;
}
