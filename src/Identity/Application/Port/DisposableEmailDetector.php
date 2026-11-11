<?php

declare(strict_types=1);

namespace App\Identity\Application\Port;

use App\Shared\Domain\ValueObject\Email;

interface DisposableEmailDetector
{
    /** Domaine (ou domaine parent) connu pour fournir des adresses temporaires. */
    public function isDisposable(Email $email): bool;
}
