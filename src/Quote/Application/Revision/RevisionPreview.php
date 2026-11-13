<?php

declare(strict_types=1);

namespace App\Quote\Application\Revision;

use App\Quote\Domain\Mention\MentionCheck;
use App\Quote\Domain\Quote\QuoteContent;

final readonly class RevisionPreview
{
    /** @param list<MentionCheck> $mentions */
    public function __construct(public QuoteContent $content, public array $mentions)
    {
    }

    public function complete(): bool
    {
        foreach ($this->mentions as $check) {
            if (!$check->ok) {
                return false;
            }
        }

        return true;
    }
}
