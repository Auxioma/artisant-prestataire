<?php

declare(strict_types=1);

namespace App\Review\Domain\Review;

/** RG-035 — en modération, publié, refusé (motif), masqué par la modération, modification demandée. */
enum ReviewStatus: string
{
    case Pending = 'pending';
    case Published = 'published';
    case Rejected = 'rejected';
    case Hidden = 'hidden';
    case ChangesRequested = 'changes_requested';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En modération',
            self::Published => 'Publié',
            self::Rejected => 'Refusé',
            self::Hidden => 'Masqué',
            self::ChangesRequested => 'Modification demandée',
        };
    }
}
