<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Alert;

use App\Billing\Application\Alert\CreditAlertMailer;
use App\Notification\Application\Notification;
use App\Notification\Application\Notifier;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** E-42 / E-42b confiés au socle Notification ; lien vers l'abonnement (PR11, lot 6) ou, en attendant, les forfaits (P13). */
final readonly class NotificationCreditAlertMailer implements CreditAlertMailer
{
    public function __construct(private Notifier $notifier, private UrlGeneratorInterface $urls)
    {
    }

    public function thresholdReached(string $email, string $firstName, string $userId, int $consumedPercent, int $remaining, int $granted): void
    {
        $link = $this->link();
        $this->notifier->notify(new Notification('E-42', $email, $userId, ['prenom' => $firstName, 'seuil' => $consumedPercent, 'restants' => $remaining, 'total' => $granted, 'lien' => $link], $link));
    }

    public function freeCreditExhausted(string $email, string $firstName, string $userId, int $granted, int $remaining): void
    {
        $link = $this->link();
        $this->notifier->notify(new Notification('E-42b', $email, $userId, ['prenom' => $firstName, 'total' => $granted, 'restants' => $remaining, 'lien' => $link], $link));
    }

    private function link(): string
    {
        try {
            return $this->urls->generate('pro_subscription', [], UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (RouteNotFoundException) {
            return $this->urls->generate('content_pricing', [], UrlGeneratorInterface::ABSOLUTE_URL);
        }
    }
}
