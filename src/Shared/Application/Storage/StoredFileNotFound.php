<?php

declare(strict_types=1);

namespace App\Shared\Application\Storage;

final class StoredFileNotFound extends \RuntimeException
{
    public static function withId(string $id): self
    {
        return new self(\sprintf('Fichier %s introuvable ou indisponible.', $id));
    }
}
