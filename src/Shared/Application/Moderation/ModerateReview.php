<?php

declare(strict_types=1);

namespace App\Shared\Application\Moderation;

use App\Shared\Application\Bus\Command;

/**
 * BO05.1 — décision sur un avis (publier, refuser avec motif, masquer avec motif, demander une modification) ou sur la
 * réponse de l'artisan (publier, refuser avec motif). Traitée par Review ; lève ModerationDecisionRejected.
 */
final readonly class ModerateReview implements Command
{
    public const array DECISIONS = ['publish', 'reject', 'hide', 'changes', 'reply_publish', 'reply_reject'];

    public function __construct(public string $adminId, public string $reviewId, public string $decision, public ?string $reason = null, public ?string $note = null)
    {
    }
}
