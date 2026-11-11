<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Signup;

use App\ArtisanProfile\Domain\Document\DocumentStatus;

/** Une pièce attendue (A03.6) et l'état de la dernière pièce déposée pour elle. */
final readonly class DocumentSlot
{
    /** @param list<string> $requiredBy métiers qui l'exigent */
    public function __construct(
        public string $type,
        public ?string $subject,
        public string $label,
        public bool $required,
        public array $requiredBy,
        public ?\DateTimeImmutable $expiresOn,
        public ?string $status,
        public ?string $fileName,
        public ?\DateTimeImmutable $uploadedAt,
    ) {
    }

    public static function key(string $type, ?string $subject): string
    {
        return null === $subject ? $type : $type.':'.$subject;
    }

    public function slotKey(): string
    {
        return self::key($this->type, $this->subject);
    }

    /** Pièce déposée et non écartée (en analyse, à contrôler ou acceptée). */
    public function provided(): bool
    {
        return null !== $this->status && DocumentStatus::from($this->status)->counts();
    }
}
