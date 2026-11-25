<?php

declare(strict_types=1);

namespace App\Catalog\Application\Proposal;

use App\Shared\Application\Bus\Command;

/**
 * P11.2 — « Je ne trouve pas ma prestation » : le client propose un métier, dans une catégorie existante
 * (`categorySlug`) ou nouvelle (`categoryName`). Le métier est créé inactif (invisible) ; un métier actif de même nom
 * est repris tel quel (proposition « merged » sans validation). Lire ensuite JobProposalOf($proposalId).
 */
final readonly class ProposeJob implements Command
{
    public function __construct(
        public string $proposalId,
        public string $jobName,
        public ?string $categorySlug,
        public ?string $categoryName,
        public ?string $userId,
    ) {
    }
}
