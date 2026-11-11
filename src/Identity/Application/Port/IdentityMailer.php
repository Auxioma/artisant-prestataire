<?php

declare(strict_types=1);

namespace App\Identity\Application\Port;

use App\Shared\Domain\ValueObject\Email;

/**
 * E-mails transactionnels du module (annexe F). Implémentation provisoire Symfony Mailer + Twig ; le socle Notification
 * (T-206 : modèles administrables, journal d'envoi, Brevo) la remplacera sans changer ce port.
 */
interface IdentityMailer
{
    /** E-01 (inscription) ou confirmation de la nouvelle adresse (changement d'e-mail). */
    public function sendEmailVerification(Email $to, string $firstName, string $token, int $ttlHours, bool $emailChange): void;

    /** E-06 : alerte envoyée à l'ancienne adresse après un changement. */
    public function sendEmailChangedAlert(Email $oldAddress, string $firstName, \DateTimeImmutable $at): void;

    /** E-03 : lien de réinitialisation du mot de passe. */
    public function sendPasswordResetLink(Email $to, string $firstName, string $token, int $ttlMinutes): void;

    /** E-04 : mot de passe modifié. */
    public function sendPasswordChanged(Email $to, string $firstName, \DateTimeImmutable $at): void;

    /** E-05 : connexion depuis un appareil jamais utilisé pour ce compte. */
    public function sendNewDeviceAlert(Email $to, string $firstName, string $device, \DateTimeImmutable $at): void;

    /** Inscription tentée avec l'adresse d'un compte existant (anti-énumération, A02). */
    public function sendExistingAccountNotice(Email $to, string $firstName): void;
}
