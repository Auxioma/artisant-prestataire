<?php

declare(strict_types=1);

namespace App\Quote\Domain\Mention;

/** Résultat du contrôle d'une mention : libellé affiché, conforme ou non, précision. */
final readonly class MentionCheck
{
    public function __construct(public MandatoryMention $mention, public bool $ok, public string $label, public ?string $missing = null)
    {
    }
}
