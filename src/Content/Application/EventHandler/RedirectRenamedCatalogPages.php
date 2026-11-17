<?php

declare(strict_types=1);

namespace App\Content\Application\EventHandler;

use App\Catalog\Application\CatalogPaths;
use App\Catalog\Domain\Event\CategorySlugChanged;
use App\Catalog\Domain\Event\JobSlugChanged;
use App\Content\Application\Port\RedirectStore;
use App\Shared\Application\Bus\EventHandler;

/** BO06 : « slug modifié → redirection 301 créée automatiquement » (préserve le référencement, CDC §7). */
final readonly class RedirectRenamedCatalogPages implements EventHandler
{
    public function __construct(private RedirectStore $redirects)
    {
    }

    public function __invoke(CategorySlugChanged|JobSlugChanged $event): void
    {
        if ($event instanceof CategorySlugChanged) {
            $this->redirects->redirect(CatalogPaths::category($event->oldSlug), CatalogPaths::category($event->newSlug), false, 'catalog');

            return;
        }

        $this->redirects->redirect(CatalogPaths::job($event->oldSlug), CatalogPaths::job($event->newSlug), false, 'catalog');
        $this->redirects->redirect(CatalogPaths::jobCityPrefix($event->oldSlug), CatalogPaths::jobCityPrefix($event->newSlug), true, 'catalog');
    }
}
