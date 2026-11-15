<?php

declare(strict_types=1);

namespace App\Quote\Infrastructure\Mailer;

use App\Notification\Application\Notification;
use App\Notification\Application\Notifier;
use App\Quote\Application\Appointment\AppointmentMailer;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class NotificationAppointmentMailer implements AppointmentMailer
{
    private const array DAYS = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
    private const array MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    private const array CHANGES = [
        'proposed' => 'vous propose un rendez-vous',
        'confirmed' => 'a confirmé le rendez-vous',
        'cancelled' => 'a annulé le rendez-vous',
        'expired' => 'n’a pas été confirmé à temps : le créneau a été libéré',
        'completed' => 'a indiqué que la prestation a eu lieu',
        'rescheduled' => 'propose un nouveau créneau',
    ];

    public function __construct(private Notifier $notifier, private UrlGeneratorInterface $urls)
    {
    }

    public function changed(string $recipient, string $email, ?string $firstName, string $userId, string $change, string $counterpart, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt, string $requestId, string $requestNumber, ?string $reason): void
    {
        $link = $this->link($recipient, $requestId);
        $this->notifier->notify(new Notification('client' === $recipient ? 'E-14' : 'E-36', $email, $userId, [
            'prenom' => $firstName ?? '',
            'interlocuteur' => $counterpart,
            'evenement' => $change,
            'action' => self::CHANGES[$change] ?? 'a modifié le rendez-vous',
            'quand' => self::when($startsAt, $endsAt),
            'motif' => $reason ?? '',
            'demande' => $requestNumber,
            'lien' => $link,
        ], $link));
    }

    public function reminder(string $recipient, string $email, ?string $firstName, string $userId, string $counterpart, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt, string $requestId, string $requestNumber): void
    {
        $link = $this->link($recipient, $requestId);
        $this->notifier->notify(new Notification('E-15', $email, $userId, [
            'prenom' => $firstName ?? '',
            'interlocuteur' => $counterpart,
            'quand' => self::when($startsAt, $endsAt),
            'demande' => $requestNumber,
            'lien' => $link,
        ], $link));
    }

    /** « mardi 17 novembre de 10:00 à 11:00 » (heure de Paris). */
    private static function when(\DateTimeImmutable $start, \DateTimeImmutable $end): string
    {
        $zone = new \DateTimeZone('Europe/Paris');
        $s = $start->setTimezone($zone);

        return \sprintf('%s %d %s de %s à %s', self::DAYS[(int) $s->format('N') - 1], (int) $s->format('j'), self::MONTHS[(int) $s->format('n') - 1], $s->format('H:i'), $end->setTimezone($zone)->format('H:i'));
    }

    private function link(string $recipient, string $requestId): string
    {
        foreach ('client' === $recipient ? [['client_appointments', []], ['client_request', ['id' => $requestId]]] : [['pro_appointments', []], ['pro_request', ['id' => $requestId]]] as [$route, $params]) {
            try {
                return $this->urls->generate($route, $params, UrlGeneratorInterface::ABSOLUTE_URL);
            } catch (RouteNotFoundException) {
            }
        }

        return $this->urls->generate('public_home', [], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
