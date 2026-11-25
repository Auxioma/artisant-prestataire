<?php

declare(strict_types=1);

namespace App\Shared\Application\Storage;

/** Fichier refusé avant stockage ; `reason` sert à choisir le message affiché (aucun détail technique exposé). */
final class RejectedUpload extends \RuntimeException
{
    public const string EMPTY = 'empty';
    public const string TOO_LARGE = 'too_large';
    public const string TYPE_NOT_ALLOWED = 'type_not_allowed';
    public const string UNREADABLE = 'unreadable';
    public const string IMAGE_TOO_LARGE = 'image_too_large';
    public const string PDF_ACTIVE_CONTENT = 'pdf_active_content';
    public const string PDF_ENCRYPTED = 'pdf_encrypted';

    public function __construct(public readonly string $reason, string $detail = '')
    {
        parent::__construct('' === $detail ? $reason : $reason.' : '.$detail);
    }
}
