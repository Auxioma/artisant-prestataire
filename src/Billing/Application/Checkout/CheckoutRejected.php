<?php

declare(strict_types=1);

namespace App\Billing\Application\Checkout;

/** Souscription refusée avant tout paiement ; `field` : bloc du formulaire concerné (billing, vat, promo, terms, plan). */
final class CheckoutRejected extends \DomainException
{
    public function __construct(string $message, public readonly string $field = 'form')
    {
        parent::__construct($message);
    }
}
