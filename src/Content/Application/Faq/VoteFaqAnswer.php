<?php

declare(strict_types=1);

namespace App\Content\Application\Faq;

use App\Shared\Application\Bus\Command;

/** P14.3 — « Cette réponse vous a-t-elle aidé ? » : un vote par visiteur (empreinte anonymisée) et par question. */
final readonly class VoteFaqAnswer implements Command
{
    public function __construct(public string $questionId, public string $visitorHash, public bool $helpful)
    {
    }
}
