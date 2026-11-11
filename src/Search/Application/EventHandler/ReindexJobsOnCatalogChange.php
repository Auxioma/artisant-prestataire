<?php

declare(strict_types=1);

namespace App\Search\Application\EventHandler;

use App\Catalog\Domain\Event\CategoryChanged;
use App\Catalog\Domain\Event\CategorySlugChanged;
use App\Catalog\Domain\Event\JobChanged;
use App\Catalog\Domain\Event\JobCreated;
use App\Catalog\Domain\Event\JobDeactivated;
use App\Catalog\Domain\Event\JobSlugChanged;
use App\Search\Application\SearchIndexer;
use App\Shared\Application\Bus\EventHandler;

/**
 * L'autocomplétion suit le référentiel (BO06 → recherche) : métier créé, modifié ou désactivé ; une modification
 * de catégorie (libellé, activation) réindexe tous les métiers (quelques centaines). Traitement asynchrone, idempotent.
 */
final readonly class ReindexJobsOnCatalogChange implements EventHandler
{
    public function __construct(private SearchIndexer $indexer)
    {
    }

    public function __invoke(JobCreated|JobChanged|JobSlugChanged|JobDeactivated|CategoryChanged|CategorySlugChanged $event): void
    {
        $this->indexer->reindexJobs($event instanceof CategoryChanged || $event instanceof CategorySlugChanged ? null : [$event->jobId]);
    }
}
