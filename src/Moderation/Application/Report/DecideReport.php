<?php

declare(strict_types=1);

namespace App\Moderation\Application\Report;

use App\Shared\Application\Bus\Command;

/**
 * BO05.2 — classer sans suite ou marquer « traité » (l'action sur le contenu se fait depuis son écran ; un avis peut
 * être masqué ici) ; réponse facultative au signalant.
 */
final readonly class DecideReport implements Command
{
    public const array DECISIONS = ['dismiss', 'actioned'];

    public function __construct(public string $adminId, public string $reportId, public string $decision, public ?string $response = null)
    {
    }
}
