<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Subscription;

use App\Billing\Application\Subscription\SubscriptionMailer;
use App\Notification\Application\Notification;
use App\Notification\Application\Notifier;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Identity\UserContact;
use App\Shared\Application\Identity\UserContactDetails;
use App\Shared\Domain\Text\FrenchNumber;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** E-40 / E-41 confiés au socle Notification ; lien vers « Mes abonnements » (PR11) et vers la facture. */
final readonly class NotificationSubscriptionMailer implements SubscriptionMailer
{
    public function __construct(private Notifier $notifier, private QueryBus $queries, private UrlGeneratorInterface $urls)
    {
    }

    public function activated(string $userId, string $planName, int $credits, \DateTimeImmutable $periodEnd, int $amountPaidCents, ?string $invoiceUrl): void
    {
        $this->send('E-40', $userId, $planName, $credits, $periodEnd, $amountPaidCents, $invoiceUrl);
    }

    public function renewed(string $userId, string $planName, int $credits, \DateTimeImmutable $periodEnd, int $amountPaidCents, ?string $invoiceUrl): void
    {
        $this->send('E-41', $userId, $planName, $credits, $periodEnd, $amountPaidCents, $invoiceUrl);
    }

    public function changed(string $userId, string $kind, string $planName, ?\DateTimeImmutable $effectiveOn): void
    {
        $contact = $this->contact($userId);
        if (null === $contact) {
            return;
        }
        $link = $this->link();
        $this->notifier->notify(new Notification('E-43', $contact->email, $userId, [
            'prenom' => $contact->firstName,
            'forfait' => $planName,
            'changement' => $kind,
            'date' => null === $effectiveOn ? '' : self::day($effectiveOn),
            'lien' => $link,
        ], $link));
    }

    public function paymentFailed(string $userId, string $planName, int $amountDueCents, int $attempt, ?\DateTimeImmutable $nextAttemptAt, ?string $invoiceUrl): void
    {
        $contact = $this->contact($userId);
        if (null === $contact) {
            return;
        }
        $link = $this->link();
        $this->notifier->notify(new Notification('E-44', $contact->email, $userId, [
            'prenom' => $contact->firstName,
            'forfait' => $planName,
            'montant' => FrenchNumber::money($amountDueCents),
            'tentative' => $attempt,
            'prochaine_tentative' => null === $nextAttemptAt ? '' : self::day($nextAttemptAt),
            'paiement' => $invoiceUrl ?? $link,
            'lien' => $link,
        ], $link));
    }

    public function suspended(string $userId, string $planName, int $graceDays, \DateTimeImmutable $cancelOn): void
    {
        $contact = $this->contact($userId);
        if (null === $contact) {
            return;
        }
        $link = $this->link();
        $this->notifier->notify(new Notification('E-45', $contact->email, $userId, [
            'prenom' => $contact->firstName,
            'forfait' => $planName,
            'jours' => $graceDays,
            'annulation' => self::day($cancelOn),
            'lien' => $link,
        ], $link));
    }

    public function cardExpiring(string $userId, ?string $brand, string $last4, int $expMonth, int $expYear): void
    {
        $contact = $this->contact($userId);
        if (null === $contact) {
            return;
        }
        $link = $this->link().'#paiement';
        $this->notifier->notify(new Notification('E-46', $contact->email, $userId, [
            'prenom' => $contact->firstName,
            'carte' => trim(ucfirst($brand ?? 'Carte').' •••• '.$last4),
            'expiration' => \sprintf('%02d/%d', $expMonth, $expYear),
            'lien' => $link,
        ], $link));
    }

    private function contact(string $userId): ?UserContactDetails
    {
        $contact = $this->queries->ask(new UserContact($userId));

        return $contact instanceof UserContactDetails ? $contact : null;
    }

    private function link(): string
    {
        return $this->urls->generate('pro_subscription', [], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    private static function day(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('Europe/Paris'))->format('d/m/Y');
    }

    private function send(string $code, string $userId, string $planName, int $credits, \DateTimeImmutable $periodEnd, int $amountPaidCents, ?string $invoiceUrl): void
    {
        $contact = $this->queries->ask(new UserContact($userId));
        if (!$contact instanceof UserContactDetails) {
            return;
        }
        $link = $this->urls->generate('pro_subscription', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $this->notifier->notify(new Notification($code, $contact->email, $userId, [
            'prenom' => $contact->firstName,
            'forfait' => $planName,
            'credits' => $credits,
            'fin_periode' => $periodEnd->setTimezone(new \DateTimeZone('Europe/Paris'))->format('d/m/Y'),
            'montant' => FrenchNumber::money($amountPaidCents),
            'facture' => $invoiceUrl ?? $link,
            'lien' => $link,
        ], $link));
    }
}
