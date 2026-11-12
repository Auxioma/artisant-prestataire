<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Draft;

/** Étape refusée : messages par champ (réaffichés à côté des champs), ou message global. */
final class DraftRejected extends \DomainException
{
    /** @param array<string, string> $errors */
    public function __construct(string $message, public readonly array $errors = [])
    {
        parent::__construct($message);
    }
}
