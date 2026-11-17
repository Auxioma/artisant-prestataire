<?php

declare(strict_types=1);

namespace App\Shared\Application\Storage\Port;

use App\Shared\Application\Storage\RejectedUpload;
use App\Shared\Domain\Storage\FileType;

/**
 * Type réel par signature (magic bytes), images ré-encodées (EXIF retiré, polyglottes neutralisés), PDF actifs refusés.
 */
interface FileSanitizer
{
    /**
     * @param list<FileType> $allowed
     *
     * @throws RejectedUpload
     */
    public function sanitize(string $path, array $allowed): SanitizedFile;
}
