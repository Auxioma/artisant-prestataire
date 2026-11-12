<?php

declare(strict_types=1);

namespace App\Content\Application\Contact;

use App\Content\Application\Port\ContactMailer;
use App\Content\Application\Port\ContactMessages;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Application\Storage\FileUploader;
use App\Shared\Application\Storage\RejectedUpload;
use App\Shared\Domain\Exception\InvalidValue;
use App\Shared\Domain\Storage\FilePurpose;
use App\Shared\Domain\Time\Clock;
use App\Shared\Domain\ValueObject\Email;
use App\Shared\Domain\ValueObject\PhoneNumber;

/**
 * P16 — contrôle et enregistrement d'un message de contact, pièce jointe passée au pipeline de fichiers (assainie,
 * chiffrée, analysée par l'antivirus), accusé de réception E-90 et alerte au support.
 */
final readonly class SubmitContactMessageHandler implements CommandHandler
{
    private const int NAME_MAX = 100;
    private const int MESSAGE_MIN = 20;
    private const int MESSAGE_MAX = 5000;
    private const int REFERENCE_MAX = 30;
    private const int BYTES_PER_MB = 1_048_576;
    private const string REFERENCE_PREFIX = 'CT-';
    private const int REFERENCE_LENGTH = 8;

    public function __construct(
        private ContactMessages $messages,
        private ContactMailer $mailer,
        private FileUploader $uploader,
        private SettingsProvider $settings,
        private Clock $clock,
    ) {
    }

    public function __invoke(SubmitContactMessage $command): void
    {
        if (!\in_array($command->reason, $this->settings->stringList(SettingKey::ContactReasons), true)) {
            throw new ContactRuleViolation('reason', 'Choisissez un motif.');
        }
        $name = trim($command->name);
        if ('' === $name || mb_strlen($name) > self::NAME_MAX) {
            throw new ContactRuleViolation('name', 'Indiquez votre nom.');
        }
        try {
            $email = new Email($command->email);
        } catch (InvalidValue) {
            throw new ContactRuleViolation('email', 'Veuillez saisir une adresse email valide.');
        }
        $phone = null;
        if (null !== $command->phone && '' !== trim($command->phone)) {
            try {
                $phone = new PhoneNumber($command->phone);
            } catch (InvalidValue) {
                throw new ContactRuleViolation('phone', 'Numéro de téléphone invalide.');
            }
        }
        $reference = null === $command->requestReference ? null : trim($command->requestReference);
        if (null !== $reference && mb_strlen($reference) > self::REFERENCE_MAX) {
            throw new ContactRuleViolation('request_reference', 'Numéro de demande ou de devis trop long.');
        }
        $message = trim(str_replace(["\r\n", "\r"], "\n", $command->message));
        if (mb_strlen($message) < self::MESSAGE_MIN) {
            throw new ContactRuleViolation('message', 'Votre message doit contenir au moins 20 caractères.');
        }
        if (mb_strlen($message) > self::MESSAGE_MAX) {
            throw new ContactRuleViolation('message', 'Votre message ne doit pas dépasser 5 000 caractères.');
        }
        if (!$command->consent) {
            throw new ContactRuleViolation('consent', 'Merci d’accepter le traitement de vos données pour que nous puissions vous répondre.');
        }

        $fileId = null;
        if (null !== $command->attachmentPath) {
            $size = filesize($command->attachmentPath);
            if (false === $size || $size > $this->settings->int(SettingKey::ContactMaxFileMb) * self::BYTES_PER_MB) {
                throw new ContactRuleViolation('attachment', \sprintf('La pièce jointe ne doit pas dépasser %d Mo.', $this->settings->int(SettingKey::ContactMaxFileMb)));
            }
            try {
                $fileId = $this->uploader->upload($command->attachmentPath, $command->attachmentName, FilePurpose::ContactAttachment)->id;
            } catch (RejectedUpload $e) {
                throw new ContactRuleViolation('attachment', match ($e->reason) {
                    RejectedUpload::TYPE_NOT_ALLOWED => 'Format non accepté : PDF, JPEG, PNG ou WebP.', RejectedUpload::PDF_ACTIVE_CONTENT => 'Ce PDF contient des éléments actifs (scripts) : exportez-le à nouveau.', RejectedUpload::PDF_ENCRYPTED => 'Ce PDF est protégé par un mot de passe.', RejectedUpload::IMAGE_TOO_LARGE => 'Image trop grande : réduisez sa résolution.', default => 'Cette pièce jointe n’a pas pu être acceptée.',
                });
            }
        }

        $contact = new ContactMessage(
            $command->id,
            self::REFERENCE_PREFIX.strtoupper(substr(str_replace('-', '', $command->id), -self::REFERENCE_LENGTH)),
            $command->reason,
            $name,
            $email->value,
            $phone?->value,
            '' === $reference ? null : $reference,
            $message,
            $fileId,
            $command->userId,
            $command->ipHash,
            $this->clock->now(),
        );
        $this->messages->add($contact);
        $this->mailer->acknowledge($contact, $this->settings->int(SettingKey::ContactReplyHours));
        $this->mailer->alertSupport($contact);
    }
}
