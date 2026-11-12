<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Moderation;

use App\Shared\Application\Bus\Command;

/**
 * BO04.1 — décision sur une ou plusieurs demandes (sélection) : valider (publication, RG-063), refuser (motif normalisé,
 * E-10b) ou clôturer. Chaque décision est journalisée.
 */
final readonly class ModerateQuoteRequests implements Command
{
    public const string APPROVE = 'approve';
    public const string REJECT = 'reject';
    public const string CLOSE = 'close';

    /** @param list<string> $requestIds */
    public function __construct(
        public string $adminId,
        public array $requestIds,
        public string $decision,
        public ?string $reason = null,
        public ?string $comment = null,
    ) {
    }
}
