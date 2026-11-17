<?php

declare(strict_types=1);

namespace App\Quote\Application\Revision;

use App\Shared\Application\Bus\QueryHandler;

final readonly class PreviewRevisionHandler implements QueryHandler
{
    public function __construct(private RevisionHandler $revisions)
    {
    }

    public function __invoke(PreviewRevision $query): RevisionPreview
    {
        return $this->revisions->preview($query);
    }
}
