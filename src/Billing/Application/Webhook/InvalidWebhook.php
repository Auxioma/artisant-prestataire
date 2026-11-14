<?php

declare(strict_types=1);

namespace App\Billing\Application\Webhook;

/** Requête refusée avant tout traitement : signature absente, invalide ou trop ancienne, corps illisible. */
final class InvalidWebhook extends \RuntimeException
{
}
