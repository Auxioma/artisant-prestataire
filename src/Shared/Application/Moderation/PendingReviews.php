<?php

declare(strict_types=1);

namespace App\Shared\Application\Moderation;

use App\Shared\Application\Bus\Query;

/** BO05.1 — avis en modération, réponses en attente et avis signalés. Traitée par Review ; réponse : list<ReviewModerationItem>. */
final readonly class PendingReviews implements Query
{
}
