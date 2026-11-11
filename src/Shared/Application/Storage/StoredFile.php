<?php

declare(strict_types=1);

namespace App\Shared\Application\Storage;

use App\Shared\Domain\Storage\FilePurpose;
use App\Shared\Domain\Storage\FileStatus;
use App\Shared\Domain\Storage\FileType;

/**
 * Métadonnées d'un fichier stocké (`shared.stored_file`). Objet nommé par son UUID ; le nom d'origine n'est conservé
 * qu'à titre d'information (nettoyé, jamais utilisé comme chemin).
 */
final readonly class StoredFile
{
    public function __construct(
        public string $id,
        public FilePurpose $purpose,
        public FileStatus $status,
        public FileType $type,
        public int $size,
        public string $sha256,
        public ?string $originalName,
        public \DateTimeImmutable $createdAt,
        public ?WrappedKey $key = null,
    ) {
    }

    public function encrypted(): bool
    {
        return null !== $this->key;
    }

    /** Nom proposé au téléchargement : jamais le nom d'origine brut. */
    public function downloadName(): string
    {
        return $this->purpose->value.'-'.substr($this->id, 0, 8).'.'.$this->type->extension();
    }
}
