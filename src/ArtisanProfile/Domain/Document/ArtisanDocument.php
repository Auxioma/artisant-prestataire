<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Domain\Document;

use App\ArtisanProfile\Domain\Document\Event\ArtisanDocumentReviewed;
use App\ArtisanProfile\Domain\Profile\ArtisanRuleViolation;
use App\Shared\Domain\AggregateRoot;

/**
 * Justificatif d'un artisan (A03.6) : référence un fichier chiffré de `shared.stored_file` (ADR-0008) — jamais son
 * contenu. Type = code du référentiel (identity, kbis, insurance_decennial, insurance_rc, rge…) ; `subject` distingue
 * plusieurs pièces du même type (nom du label pour une qualification).
 */
final class ArtisanDocument extends AggregateRoot
{
    private const string TYPE = '/^[a-z][a-z_]{1,39}$/';
    private const int SUBJECT_MAX = 80;
    private const int REASON_MAX = 200;

    private DocumentStatus $status = DocumentStatus::Quarantined;
    private ?string $reason = null;
    private ?string $reviewedBy = null;
    private ?\DateTimeImmutable $reviewedAt = null;
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

    /** BO03 — pièce contrôlée et acceptée par un administrateur habilité (RG-032 : « vérifié » après contrôle). */
    public function approve(string $adminId, \DateTimeImmutable $now): void
    {
        if (DocumentStatus::PendingReview !== $this->status) {
            throw new ArtisanRuleViolation('Seule une pièce analysée et en attente de contrôle peut être validée.');
        }
        $this->review(DocumentStatus::Approved, $adminId, null, $now);
    }

    /** BO03 — pièce refusée avec motif (illisible, expirée, non conforme…) : l'artisan est invité à la redéposer. */
    public function reject(string $adminId, string $reason, \DateTimeImmutable $now): void
    {
        if (!\in_array($this->status, [DocumentStatus::PendingReview, DocumentStatus::Approved], true)) {
            throw new ArtisanRuleViolation('Cette pièce ne peut pas être refusée dans son état actuel.');
        }
        $reason = trim((string) preg_replace('/\s+/u', ' ', $reason));
        if ('' === $reason || mb_strlen($reason) > self::REASON_MAX) {
            throw new ArtisanRuleViolation(\sprintf('Motif du refus obligatoire (%d caractères au maximum).', self::REASON_MAX));
        }
        $this->review(DocumentStatus::Rejected, $adminId, $reason, $now);
    }

    private function review(DocumentStatus $status, string $adminId, ?string $reason, \DateTimeImmutable $now): void
    {
        $this->status = $status;
        $this->reason = $reason;
        $this->reviewedBy = $adminId;
        $this->reviewedAt = $now;
        $this->updatedAt = $now;
        $this->recordEvent(new ArtisanDocumentReviewed($this->id, $this->artisanId, $this->type, $this->subject, $status->value, $reason, $adminId, $now));
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    public function reviewedAt(): ?\DateTimeImmutable
    {
        return $this->reviewedAt;
    }

    /** RG-032 : à échéance, une pièce validée (ou en attente de contrôle) passe « expirée » ; l'artisan est alerté (E-25). */
    public function expire(\DateTimeImmutable $now): bool
    {
        if (null === $this->expiresOn || $this->expiresOn->format('Y-m-d') >= $now->format('Y-m-d') || !\in_array($this->status, [DocumentStatus::Approved, DocumentStatus::PendingReview], true)) {
            return false;
        }
        $this->status = DocumentStatus::Expired;
        $this->updatedAt = $now;

        return true;
    }

    /** CDC §11 — conservation échue (fin de la relation + documents.retention_after_relation_years) : fichier détruit. */
    public function shredForRetention(\DateTimeImmutable $now): void
    {
        $this->status = DocumentStatus::Shredded;
        $this->reason = 'retention';
        $this->updatedAt = $now;
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
