<?php

declare(strict_types=1);

namespace App\Shared\Domain\Storage;

/**
 * Usage d'un fichier : fixe les types acceptés et le chiffrement au repos (cadrage §5.5, ADR-0008).
 * Justificatifs artisans (Kbis, assurances, pièce d'identité — Q16) : chiffrés, lus via un contrôleur journalisé.
 * Autres usages : stockage privé, lecture par URL signée de courte durée après passage du Voter.
 */
enum FilePurpose: string
{
    case ArtisanDocument = 'artisan_document';
    /** Logo ou photo du profil public (A03.7) : image, non chiffrée, exposée une fois saine. */
    case ArtisanLogo = 'artisan_logo';
    case RequestPhoto = 'request_photo';
    case MessageAttachment = 'message_attachment';
    case QuotePdf = 'quote_pdf';
    case ReviewPhoto = 'review_photo';
    case PortfolioPhoto = 'portfolio_photo';
    case ContactAttachment = 'contact_attachment';
    case ClientAvatar = 'client_avatar';
    /** Archive « Télécharger mes données » (C07.4), générée par l'application. */
    case DataExport = 'data_export';
    /** BO10.7 — médiathèque du CMS (images publiques, variantes WebP / AVIF générées). */
    case Media = 'media';

    /** @return list<FileType> */
    public function allowedTypes(): array
    {
        return match ($this) {
            self::ArtisanDocument, self::MessageAttachment, self::ContactAttachment => [FileType::Pdf, FileType::Jpeg, FileType::Png, FileType::Webp],
            self::RequestPhoto => [FileType::Jpeg, FileType::Png, FileType::Webp, FileType::Pdf], // RG-061 : photos et PDF
            self::ArtisanLogo, self::ReviewPhoto, self::PortfolioPhoto, self::ClientAvatar => [FileType::Jpeg, FileType::Png, FileType::Webp],
            self::QuotePdf => [FileType::Pdf],
            self::DataExport => [FileType::Zip],
            self::Media => [FileType::Jpeg, FileType::Png, FileType::Webp],
        };
    }

    public function accepts(FileType $type): bool
    {
        return \in_array($type, $this->allowedTypes(), true);
    }

    /**
     * Destiné à l'affichage public (logo, photos de réalisations d'un artisan public, photos d'un avis publié) : lecture
     * sans journalisation, cache HTTP possible — le module propriétaire vérifie d'abord que l'élément est publié.
     */
    public function isPublic(): bool
    {
        return self::ArtisanLogo === $this || self::PortfolioPhoto === $this || self::ReviewPhoto === $this || self::Media === $this;
    }

    public function encryptedAtRest(): bool
    {
        return self::ArtisanDocument === $this || self::ContactAttachment === $this || self::DataExport === $this;
    }
}
