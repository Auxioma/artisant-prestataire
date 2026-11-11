<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Domain\Document;

use App\ArtisanProfile\Domain\Profile\ArtisanRuleViolation;

/**
 * Justificatif d'un artisan (A03.6) : référence un fichier chiffré de `shared.stored_file` (ADR-0008) — jamais son
 * contenu. Type = code du référentiel (identity, kbis, insurance_decennial, insurance_rc, rge…) ; `subject` distingue
 * plusieurs pièces du même type (nom du label pour une qualification).
 */
final class ArtisanDocument
{
    private const string TYPE = '/^[a-z][a-z_]{1,39}$/';
    private const int SUBJECT_MAX = 80;

    private DocumentStatus $status = DocumentStatus::Quarantined;
    private ?string $reason = null;
    private \DateTimeImmutable $updatedAt;

    private function __construct(
        private string $id,
        private string $artisanId,
        private string $type,
        private ?string $subject,
        private string $fileId,
        private ?string $originalName,
        private ?\DateTimeImmutable $expiresOn,
        private \DateTimeImmutable $uploadedAt,
    ) {
        $this->updatedAt = $uploadedAt;
    }

    public static function upload(string $id, string $artisanId, string $type, ?string $subject, string $fileId, ?string $originalName, ?\DateTimeImmutable $expiresOn, \DateTimeImmutable $now): self
    {
        if (1 !== preg_match(self::TYPE, $type)) {
            throw new ArtisanRuleViolation('Type de justificatif inconnu.');
        }
        if (null !== $subject && ('' === trim($subject) || mb_strlen($subject) > self::SUBJECT_MAX)) {
            throw new ArtisanRuleViolation('Justificatif invalide.');
        }

        return new self($id, $artisanId, $type, $subject, $fileId, $originalName, $expiresOn, $now);
    }

    /** Analyse antivirus réussie : à contrôler par un administrateur habilité (BO03). */
    public function markScanned(\DateTimeImmutable $now): void
    {
        if (DocumentStatus::Quarantined === $this->status) {
            $this->status = DocumentStatus::PendingReview;
            $this->updatedAt = $now;
        }
    }

    public function markRejectedByScan(\DateTimeImmutable $now): void
    {
        if (DocumentStatus::Quarantined === $this->status) {
            $this->status = DocumentStatus::Rejected;
            $this->reason = 'antivirus';
            $this->updatedAt = $now;
        }
    }

    /** Remplacé par une nouvelle pièce du même type : le fichier est détruit (crypto-shredding) par le cas d'usage. */
    public function supersede(\DateTimeImmutable $now): void
    {
        $this->status = DocumentStatus::Shredded;
        $this->reason = 'replaced';
        $this->updatedAt = $now;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function artisanId(): string
    {
        return $this->artisanId;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function subject(): ?string
    {
        return $this->subject;
    }

    public function fileId(): string
    {
        return $this->fileId;
    }

    public function originalName(): ?string
    {
        return $this->originalName;
    }

    public function status(): DocumentStatus
    {
        return $this->status;
    }

    public function expiresOn(): ?\DateTimeImmutable
    {
        return $this->expiresOn;
    }

    public function uploadedAt(): \DateTimeImmutable
    {
        return $this->uploadedAt;
    }
}
