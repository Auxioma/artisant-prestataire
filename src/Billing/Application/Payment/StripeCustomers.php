<?php

declare(strict_types=1);

namespace App\Billing\Application\Payment;

use App\Billing\Application\Port\PaymentGateway;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Identity\UserContact;
use App\Shared\Application\Identity\UserContactDetails;
use App\Shared\Application\Integration\ExternalServiceUnavailable;
use App\Shared\Domain\Time\Clock;

/**
 * T-602 — un client Stripe par artisan, créé à la première souscription (tunnel PR12, T-603) puis réutilisé. Deux
 * demandes simultanées utilisent la même clé d'idempotence : Stripe ne crée qu'un client, la base n'en garde qu'un.
 * Seuls l'adresse, le nom et les identifiants internes sont transmis (adresse de facturation et TVA : au tunnel).
 */
final readonly class StripeCustomers
{
    public function __construct(
        private PaymentGateway $gateway,
        private StripeCustomerAccounts $accounts,
        private QueryBus $queries,
        private Clock $clock,
    ) {
    }

    /** @throws ExternalServiceUnavailable */
    public function idFor(string $artisanId, string $userId): string
    {
        $existing = $this->accounts->customerId($artisanId);
        if (null !== $existing) {
            return $existing;
        }
        $contact = $this->queries->ask(new UserContact($userId));
        if (!$contact instanceof UserContactDetails) {
            throw new \DomainException('Compte introuvable ou anonymisé : aucun client de paiement.');
        }
        $customerId = $this->gateway->createCustomer(
            $contact->email,
            trim($contact->firstName.' '.$contact->lastName),
            ['artisan_id' => $artisanId, 'user_id' => $userId],
            IdempotencyKey::for('EnsureStripeCustomer', $artisanId, 'customer'),
        );

        return $this->accounts->linkCustomer($artisanId, $userId, $customerId, $this->clock->now());
    }
}
