<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Dispatch;

use App\Shared\Application\Bus\Command;

/**
 * PR14.2 — envoie les digests E-30 en attente : à chaque passage pour la fréquence « 2 fois par jour », au seul
 * passage du matin (`$daily`) pour la fréquence « quotidien ».
 */
final readonly class SendRequestDigests implements Command
{
    public function __construct(public bool $daily)
    {
    }
}
