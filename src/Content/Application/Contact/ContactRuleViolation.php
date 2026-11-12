<?php

declare(strict_types=1);

namespace App\Content\Application\Contact;

/** Saisie refusée, avec le champ concerné pour l'afficher au bon endroit. */
final class ContactRuleViolation extends \DomainException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}
