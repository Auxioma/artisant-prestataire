<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Catalog\Application\Command\CreateCategory;
use App\Catalog\Application\Command\CreateJob;
use App\Catalog\Application\Command\DeactivateJob;
use App\Catalog\Application\Command\UpdateCategory;
use App\Catalog\Application\Command\UpdateJob;
use App\Catalog\Application\Query\CategorySummary;
use App\Catalog\Application\Query\MainCategories;
use App\Catalog\Domain\Exception\CatalogRuleViolation;
use App\Catalog\Domain\Job\JobRepository;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Domain\ValueObject\Slug;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** Catalog : persistance Doctrine (mapping XML), unicité, requête du méga-menu, import idempotent, audit. */
final class CatalogPersistenceTest extends KernelTestCase
{
    private CommandBus $commands;
    private QueryBus $queries;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $commands = $container->get(CommandBus::class);
        $queries = $container->get(QueryBus::class);
        $connection = $container->get(Connection::class);
        \assert($commands instanceof CommandBus && $queries instanceof QueryBus && $connection instanceof Connection);
        [$this->commands, $this->queries, $this->connection] = [$commands, $queries, $connection];
    }

    private function scalar(string $sql): int
    {
        return DbalValue::int($this->connection->fetchOne($sql));
    }

    /** @return list<CategorySummary> */
    private function menu(int $jobs = 5): array
    {
        $result = $this->queries->ask(new MainCategories($jobs));
        self::assertIsArray($result);

        return array_values(array_filter($result, static fn (mixed $c): bool => $c instanceof CategorySummary));
    }

    public function testCreatesTreeAndEnforcesUniqueSlugs(): void
    {
        $this->commands->dispatch(new CreateCategory('Plomberie', 'wrench', featured: true));
        $this->commands->dispatch(new CreateCategory('Sanitaire', 'wrench', parentSlug: 'plomberie'));
        $this->commands->dispatch(new CreateJob('plomberie', 'Plombier', synonyms: ['Chauffagiste sanitaire'], requiredDocuments: ['kbis']));
        $this->commands->dispatch(new CreateJob('sanitaire', 'Débouchage'));

        self::assertSame(2, $this->scalar('SELECT count(*) FROM catalog.category'));
        self::assertSame('["Chauffagiste sanitaire"]', DbalValue::string($this->connection->fetchOne("SELECT synonyms::text FROM catalog.job WHERE slug = 'plombier'")));
        self::assertSame(1, $this->scalar("SELECT count(*) FROM catalog.category WHERE slug = 'sanitaire' AND parent_id IS NOT NULL"));
        self::assertSame(4, $this->scalar("SELECT count(*) FROM admin.audit_log WHERE action IN ('catalog.category_created', 'catalog.job_created')"));
        self::assertGreaterThan(0, $this->scalar("SELECT count(*) FROM shared.outbox_message WHERE message_type LIKE 'App\\\\Catalog%'"), 'Événements publiés en outbox');

        $this->expectException(CatalogRuleViolation::class);
        $this->commands->dispatch(new CreateJob('plomberie', 'Plombier'));
    }

    public function testMainCategoriesQuery(): void
    {
        $this->commands->dispatch(new CreateCategory('Plomberie', 'wrench'));
        $this->commands->dispatch(new CreateCategory('Sanitaire', 'wrench', parentSlug: 'plomberie'));
        $this->commands->dispatch(new CreateCategory('Électricité', 'zap'));
        $this->commands->dispatch(new CreateCategory('Vide', 'home'));
        foreach (['Plombier', 'Chauffe-eau', 'Débouchage', 'Salle de bains'] as $name) {
            $this->commands->dispatch(new CreateJob('plomberie', $name));
        }
        $this->commands->dispatch(new CreateJob('sanitaire', 'Robinetterie'));
        $this->commands->dispatch(new CreateJob('electricite', 'Électricien'));
        $this->commands->dispatch(new DeactivateJob('electricien'));

        $menu = $this->menu(3);

        self::assertSame(['plomberie'], array_map(static fn (CategorySummary $c): string => $c->slug, $menu), 'Catégories sans métier actif exclues (P03)');
        self::assertSame(5, $menu[0]->activeJobCount, 'Métiers des sous-catégories inclus');
        self::assertSame(['plombier', 'chauffe-eau', 'debouchage'], array_column($menu[0]->jobs, 'slug'), 'Ordre du BO, limite 3 à 5 (P01.1)');

        $this->commands->dispatch(new UpdateCategory('plomberie', active: false));
        self::assertSame([], $this->menu(), 'Catégorie désactivée retirée du méga-menu');
    }

    public function testRenamingJobKeepsIdentityAndCreatesRedirects(): void
    {
        $this->commands->dispatch(new CreateCategory('Plomberie', 'wrench'));
        $this->commands->dispatch(new CreateJob('plomberie', 'Plombier'));
        $this->commands->dispatch(new UpdateJob('plombier', name: 'Plombier chauffagiste', newSlug: 'plombier-chauffagiste'));

        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $em->clear();
        $jobs = self::getContainer()->get(JobRepository::class);
        \assert($jobs instanceof JobRepository);
        $job = $jobs->findBySlug(new Slug('plombier-chauffagiste'));
        self::assertNotNull($job);
        self::assertSame('Plombier chauffagiste', $job->name());
        self::assertSame(1, $this->scalar("SELECT count(*) FROM shared.outbox_message WHERE message_type LIKE '%JobSlugChanged'"));
    }

    public function testImportIsIdempotent(): void
    {
        $kernel = self::$kernel;
        \assert(null !== $kernel);
        $tester = new CommandTester(new Application($kernel)->find('app:catalog:import'));

        self::assertSame(0, $tester->execute([]));
        $categories = $this->scalar('SELECT count(*) FROM catalog.category');
        $jobs = $this->scalar('SELECT count(*) FROM catalog.job');
        self::assertSame(6, $categories);
        self::assertSame(24, $jobs);
        self::assertSame(2, $this->scalar('SELECT count(*) FROM catalog.job WHERE decennial_required'));

        $tester->execute([]);
        self::assertStringContainsString('0 catégorie(s) et 0 métier(s)', $tester->getDisplay());
        self::assertSame($jobs, $this->scalar('SELECT count(*) FROM catalog.job'));
    }
}
