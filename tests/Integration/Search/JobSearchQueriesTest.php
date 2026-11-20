<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Catalog\Application\Command\CreateCategory;
use App\Catalog\Application\Command\CreateJob;
use App\Catalog\Application\Command\DeactivateJob;
use App\Catalog\Application\Command\UpdateCategory;
use App\Catalog\Application\Query\FindJobs;
use App\Catalog\Application\Query\JobIndexEntries;
use App\Catalog\Application\Query\JobIndexEntry;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Requêtes Catalog qui alimentent l'index et le repli PostgreSQL de l'autocomplétion (RG-010). */
final class JobSearchQueriesTest extends KernelTestCase
{
    private CommandBus $commands;
    private QueryBus $queries;

    protected function setUp(): void
    {
        self::bootKernel();
        $commands = self::getContainer()->get(CommandBus::class);
        $queries = self::getContainer()->get(QueryBus::class);
        \assert($commands instanceof CommandBus && $queries instanceof QueryBus);
        [$this->commands, $this->queries] = [$commands, $queries];

        $this->commands->dispatch(new CreateCategory('Plomberie', 'wrench'));
        $this->commands->dispatch(new CreateCategory('Électricité', 'bolt'));
        $this->commands->dispatch(new CreateJob('plomberie', 'Plombier', synonyms: ['Chauffagiste sanitaire']));
        $this->commands->dispatch(new CreateJob('plomberie', 'Déboucheur 100%'));
        $this->commands->dispatch(new CreateJob('electricite', 'Électricien'));
    }

    /** @return list<JobIndexEntry> */
    private function ask(FindJobs|JobIndexEntries $query): array
    {
        $result = $this->queries->ask($query);
        self::assertIsArray($result);

        return array_values(array_filter($result, static fn (mixed $e): bool => $e instanceof JobIndexEntry));
    }

    /** @return list<string> */
    private function slugs(FindJobs|JobIndexEntries $query): array
    {
        return array_map(static fn (JobIndexEntry $e): string => $e->slug, $this->ask($query));
    }

    public function testFindJobsIgnoresAccentsAndMatchesSynonyms(): void
    {
        self::assertSame(['electricien'], $this->slugs(new FindJobs('ELECTRI')));
        self::assertSame(['plombier'], $this->slugs(new FindJobs('chauffagiste')));
        self::assertSame([], $this->slugs(new FindJobs('%')), 'joker LIKE échappé');
        self::assertSame(['deboucheur-100'], $this->slugs(new FindJobs('100%')));
    }

    public function testInactiveJobsAndCategoriesAreNotSearchable(): void
    {
        $this->commands->dispatch(new DeactivateJob('electricien'));
        $this->commands->dispatch(new UpdateCategory('plomberie', active: false));

        self::assertSame([], $this->slugs(new FindJobs('plomb')));
        self::assertSame([], $this->slugs(new FindJobs('electri')));

        $entries = $this->ask(new JobIndexEntries());
        self::assertCount(3, $entries);
        self::assertSame([false, false, false], array_map(static fn (JobIndexEntry $e): bool => $e->searchable, $entries));
        self::assertSame('Plomberie', $entries[array_search('plombier', array_map(static fn (JobIndexEntry $e): string => $e->slug, $entries), true)]->categoryName);
    }

    public function testIndexEntriesCanBeRestrictedToIds(): void
    {
        $all = $this->ask(new JobIndexEntries());
        $one = $this->ask(new JobIndexEntries([$all[0]->id]));

        self::assertCount(1, $one);
        self::assertSame($all[0]->id, $one[0]->id);
        self::assertSame([], $this->ask(new JobIndexEntries([])));
    }
}
