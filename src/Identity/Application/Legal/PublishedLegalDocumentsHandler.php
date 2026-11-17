<?php

declare(strict_types=1);

namespace App\Identity\Application\Legal;

use App\Identity\Application\Port\LegalDocuments;
use App\Identity\Domain\Legal\LegalDocumentType;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Application\Legal\PublishedLegalDocuments;
use App\Shared\Domain\Time\Clock;

final readonly class PublishedLegalDocumentsHandler implements QueryHandler
{
    public function __construct(private LegalDocuments $documents, private Clock $clock)
    {
    }

    /** @return array<string, \DateTimeImmutable> */
    public function __invoke(PublishedLegalDocuments $query): array
    {
        $published = [];
        foreach (LegalDocumentType::cases() as $type) {
            $current = $this->documents->current($type, $this->clock->now());
            if (null !== $current) {
                $published[$type->slug()] = $current->publishedAt;
            }
        }

        return $published;
    }
}
