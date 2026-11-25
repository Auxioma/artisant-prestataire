<?php

declare(strict_types=1);

namespace App\Catalog\Application\Proposal;

use App\Shared\Application\Bus\Command;

/**
 * BO06 — décision sur un métier proposé (et toutes les propositions en attente du même métier) :
 * APPROVE (libellé et catégorie ajustables, métier et catégorie activés), MERGE (rattaché à `replacementSlug`) ou
 * REJECT (motif obligatoire).
 */
final readonly class DecideJobProposal implements Command
{
    public const string APPROVE = 'approve';
    public const string MERGE = 'merge';
    public const string REJECT = 'reject';

    public function __construct(
        public string $proposalId,
        public string $adminId,
        public string $decision,
        public ?string $name = null,
        public ?string $categorySlug = null,
        public ?string $replacementSlug = null,
        public ?string $note = null,
    ) {
    }
}
