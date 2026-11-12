<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Mailer;

use App\Identity\Application\Port\IdentityMailer;
use App\Notification\Application\Notification;
use App\Notification\Application\Notifier;
use App\Shared\Domain\ValueObject\Email;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * E-mails du module Identity confiés au socle Notification (modèles BO11, journal d'envoi). Liens absolus construits
 * ici (DEFAULT_URI hors requête HTTP) ; aucune donnée au-delà des variables déclarées dans le catalogue (annexe F).
 */
final readonly class NotificationIdentityMailer implements IdentityMailer
{
    public function __construct(private Notifier $notifier, private UrlGeneratorInterface $urls)
    {
    }

    public function sendEmailVerification(Email $to, string $firstName, string $token, int $ttlHours, bool $emailChange): void
    {
        $this->notify('E-01', $to, [
            'prenom' => $firstName,
            'lien' => $this->urls->generate('identity_verify_email', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL),
            'validite_heures' => $ttlHours,
            'changement_adresse' => $emailChange,
        ]);
    }

    public function sendEmailChangedAlert(Email $oldAddress, string $firstName, \DateTimeImmutable $at): void
    {
        $this->notify('E-06', $oldAddress, ['prenom' => $firstName, 'date' => $at, 'lien_aide' => $this->absolute('content_contact')]);
    }

    public function sendPasswordResetLink(Email $to, string $firstName, string $token, int $ttlMinutes): void
    {
        $this->notify('E-03', $to, [
            'prenom' => $firstName,
            'lien' => $this->urls->generate('identity_password_reset', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL),
            'validite_minutes' => $ttlMinutes,
        ]);
    }

    public function sendPasswordChanged(Email $to, string $firstName, \DateTimeImmutable $at, ?string $userId = null): void
    {
        $this->notify('E-04', $to, [
            'prenom' => $firstName,
            'date' => $at,
            'lien_reinitialisation' => $this->absolute('identity_password_forgotten'),
            'lien_aide' => $this->absolute('content_contact'),
        ], $userId);
    }

    public function sendNewDeviceAlert(Email $to, string $firstName, string $device, \DateTimeImmutable $at): void
    {
        $this->notify('E-05', $to, ['prenom' => $firstName, 'appareil' => $device, 'date' => $at, 'lien_reinitialisation' => $this->absolute('identity_password_forgotten')]);
    }

    public function sendExistingAccountNotice(Email $to, string $firstName): void
    {
        $this->notify('I-01', $to, ['prenom' => $firstName, 'lien_connexion' => $this->absolute('identity_login'), 'lien_reinitialisation' => $this->absolute('identity_password_forgotten')]);
    }

    /** @param array<string, scalar|\DateTimeInterface|null> $variables */
    private function notify(string $code, Email $to, array $variables, ?string $userId = null): void
    {
        $this->notifier->notify(new Notification($code, $to->value, $userId, $variables));
    }

    /** Page pas encore livrée : racine du site (aucun lien mort dans un e-mail). */
    public function sendAccountStatus(Email $to, string $firstName, bool $suspended, ?string $reason, string $userId): void
    {
        $this->notifier->notify(new Notification('E-26', $to->value, $userId, [
            'prenom' => $firstName,
            'suspendu' => $suspended,
            'motif' => $reason,
            'lien_aide' => $this->absolute('content_contact'),
        ]));
    }

    private function absolute(string $route): string
    {
        try {
            return $this->urls->generate($route, [], UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (RouteNotFoundException) {
            return $this->urls->generate('public_home', [], UrlGeneratorInterface::ABSOLUTE_URL);
        }
    }
}
