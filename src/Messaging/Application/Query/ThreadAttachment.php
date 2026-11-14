<?php

declare(strict_types=1);

namespace App\Messaging\Application\Query;

/** Pièce jointe d'un message : lisible seulement une fois analysée (`available`). */
final readonly class ThreadAttachment
{
    public function __construct(
        public string $fileId,
        public ?string $name,
        public string $mime,
        public int $size,
        public string $status,
    ) {
    }

    public function available(): bool
    {
        return 'available' === $this->status;
    }

    public function image(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }

    /** Nom affiché : nom d'origine nettoyé, sinon générique. */
    public function label(): string
    {
        return $this->name ?? ($this->image() ? 'Image' : 'Document PDF');
    }
}
