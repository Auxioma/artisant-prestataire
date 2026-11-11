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

    /** @return list<FileType> */
    public function allowedTypes(): array
    {
        return match ($this) {
            self::ArtisanDocument, self::MessageAttachment => [FileType::Pdf, FileType::Jpeg, FileType::Png, FileType::Webp],
            self::ArtisanLogo, self::RequestPhoto, self::ReviewPhoto, self::PortfolioPhoto => [FileType::Jpeg, FileType::Png, FileType::Webp],
            self::QuotePdf => [FileType::Pdf],
        };
    }

    public function accepts(FileType $type): bool
    {
        return \in_array($type, $this->allowedTypes(), true);
    }

    public function encryptedAtRest(): bool
    {
        return self::ArtisanDocument === $this;
    }
}
