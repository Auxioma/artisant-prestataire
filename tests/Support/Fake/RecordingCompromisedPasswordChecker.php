<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake;

use App\Identity\Application\Port\CompromisedPasswordChecker;

/** Contrôle HIBP simulé : enregistre les mots de passe soumis. */
final class RecordingCompromisedPasswordChecker implements CompromisedPasswordChecker
{
    /** @var list<string> */
    public array $checked = [];

    public function __construct(private readonly bool $compromised)
    {
    }

    public function isCompromised(#[\SensitiveParameter] string $plain): bool
    {
        $this->checked[] = $plain;

        return $this->compromised;
    }
}
