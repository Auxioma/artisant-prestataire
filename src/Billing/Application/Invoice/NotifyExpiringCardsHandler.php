<?php

declare(strict_types=1);

namespace App\Billing\Application\Invoice;

use App\Billing\Application\Subscription\SubscriptionMailer;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Domain\Time\Clock;

/**
 * PR13 — E-46 : la carte par défaut d'un abonné expire dans moins d'un mois (fin du mois d'expiration). Un seul envoi par
 * carte ; une nouvelle carte (portail Stripe, webhook) remet le signalement à zéro. Abonnements résiliés exclus.
 */
final readonly class NotifyExpiringCardsHandler implements CommandHandler
{
    /** Délai de prévenance avant la fin du mois d'expiration de la carte. */
    private const string NOTICE = '+1 month';

    public function __construct(private Invoices $invoices, private SubscriptionMailer $mailer, private Clock $clock)
    {
    }

    public function __invoke(NotifyExpiringCards $command): void
    {
        $now = $this->clock->now();
        foreach ($this->invoices->cardsExpiringBefore($now->modify(self::NOTICE)) as $card) {
            $this->mailer->cardExpiring($card['user_id'], $card['brand'], $card['last4'], $card['exp_month'], $card['exp_year']);
            $this->invoices->markExpiryNotified($card['artisan_id'], $now);
        }
    }
}
