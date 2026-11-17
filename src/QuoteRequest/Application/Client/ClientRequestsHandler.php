<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Client;

use App\Catalog\Application\Query\JobIndexEntries;
use App\Catalog\Application\Query\JobIndexEntry;
use App\QuoteRequest\Application\Draft\DraftOwner;
use App\QuoteRequest\Application\Draft\DraftStore;
use App\QuoteRequest\Application\Port\ClientRequestReader;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Domain\Time\Clock;

final readonly class ClientRequestsHandler implements QueryHandler
{
    public function __construct(private ClientRequestReader $reader, private DraftStore $drafts, private QueryBus $queries, private Clock $clock)
    {
    }

    public function __invoke(ClientRequests $query): ClientRequestPage
    {
        $result = 'brouillons' === $query->group ? ['items' => [], 'total' => 0] : $this->reader->page($query, $this->clock->now());

        return new ClientRequestPage($result['items'], $result['total'], max(1, $query->page), $query->perPage, $this->reader->stats($query->clientId), $this->draft($query->clientId));
    }

    private function draft(string $clientId): ?ClientDraft
    {
        $draft = $this->drafts->find(DraftOwner::user($clientId));
        if (null === $draft) {
            return null;
        }
        $jobName = null;
        if (null !== $draft->jobId) {
            $entries = $this->queries->ask(new JobIndexEntries([$draft->jobId]));
            foreach (\is_array($entries) ? $entries : [] as $entry) {
                $jobName = $entry instanceof JobIndexEntry ? $entry->name : $jobName;
            }
        }

        return new ClientDraft($jobName, $draft->step, $draft->updatedAt, $draft->title);
    }
}
