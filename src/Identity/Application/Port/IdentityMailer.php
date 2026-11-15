<?php

declare(strict_types=1);

namespace App\Identity\Application\Port;

use App\Identity\Application\Legal\LegalDocumentVersion;
use App\Shared\Domain\ValueObject\Email;

/**
 * E-mails transactionnels du module (annexe F). Implémentation provisoire Symfony Mailer + Twig ; le socle Notification
 * (T-206 : modèles administrables, journal d'envoi, Brevo) la remplacera sans changer ce port.
 */
interface IdentityMailer
{
    /** E-01 (inscription) ou confirmation de la nouvelle adresse (changement d'e-mail). */
    /** E-01, ou E-11 quand le compte a été créé dans le tunnel de demande (P11.6). */
    public function sendEmailVerification(Email $to, string $firstName, string $token, int $ttlHours, bool $emailChange, bool $fromRequest = false, bool $passwordless = false): void;

    /** E-06 : alerte envoyée à l'ancienne adresse après un changement. */
    public function sendEmailChangedAlert(Email $oldAddress, string $firstName, \DateTimeImmutable $at): void;

    /** E-03 : lien de réinitialisation du mot de passe. */
    public function sendPasswordResetLink(Email $to, string $firstName, string $token, int $ttlMinutes): void;

    /** E-04 : mot de passe modifié. */
    /** @param string|null $userId compte destinataire de la notification in-app (E-04 : canaux E et I) */
    public function sendPasswordChanged(Email $to, string $firstName, \DateTimeImmutable $at, ?string $userId = null): void;

    /** E-05 : connexion depuis un appareil jamais utilisé pour ce compte. */
    public function sendNewDeviceAlert(Email $to, string $firstName, string $device, \DateTimeImmutable $at): void;

    /** Inscription tentée avec l'adresse d'un compte existant (anti-énumération, A02). */
    public function sendExistingAccountNotice(Email $to, string $firstName): void;

    /** E-26 — compte suspendu (avec motif) ou réactivé. */
    public function sendAccountStatus(Email $to, string $firstName, bool $suspended, ?string $reason, string $userId): void;

    /** E-95 — information de mise à jour des CGU / CGV (acceptation demandée à la prochaine connexion si substantielle). */
    public function sendLegalUpdate(Email $to, string $firstName, string $userId, LegalDocumentVersion $document): void;

    /** E-07 : archive « Mes données » prête (lien vers l'espace, valable jusqu'à `$expiresAt`). */
    public function sendDataExportReady(Email $to, string $firstName, string $userId, int $validityDays, \DateTimeImmutable $expiresAt): void;

    /** E-08 : demande de suppression enregistrée, date d'effacement et lien d'annulation (valable jusqu'à cette date). */
    public function sendDeletionRequested(Email $to, string $firstName, string $userId, \DateTimeImmutable $effectiveAt): void;

    /** E-09 : compte effacé (envoyé à l'adresse d'origine, avant anonymisation). */
    public function sendAccountDeleted(Email $to, string $firstName): void;
}
