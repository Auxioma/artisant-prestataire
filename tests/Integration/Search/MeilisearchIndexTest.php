<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Search\Application\Port\JobDocument;
use App\Search\Application\Port\PlaceDocument;
use App\Search\Infrastructure\Meilisearch\MeilisearchAutocompleteIndex;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * ADR-0004 : réglages réels du moteur (tolérance aux fautes, synonymes, classement par population), sur des index
 * préfixés « tm_test_ » supprimés après chaque test. Requiert le service Meilisearch (Docker, CI).
 */
final class MeilisearchIndexTest extends KernelTestCase
{
    private MeilisearchAutocompleteIndex $index;

    protected function setUp(): void
    {
        self::bootKernel();
        $key = $_SERVER['MEILISEARCH_ADMIN_KEY'] ?? $_ENV['MEILISEARCH_ADMIN_KEY'] ?? '';
        if (!\is_string($key) || '' === $key) {
            self::markTestSkipped('MEILISEARCH_ADMIN_KEY non défini : moteur de recherche non configuré.');
        }
        $index = self::getContainer()->get(MeilisearchAutocompleteIndex::class);
        \assert($index instanceof MeilisearchAutocompleteIndex);
        $this->index = $index;
    }

    protected function tearDown(): void
    {
        $admin = self::getContainer()->get('meilisearch_admin.client');
        \assert($admin instanceof HttpClientInterface);
        foreach (['jobs', 'places', 'jobs_staging', 'places_staging'] as $name) {
            $admin->request('DELETE', '/indexes/tm_test_'.$name)->getStatusCode();
        }
        parent::tearDown();
    }

    /**
     * @param list<JobDocument> $jobs
     *
     * @return list<string>
     */
    private static function slugs(array $jobs): array
    {
        return array_map(static fn (JobDocument $j): string => $j->slug, $jobs);
    }

    public function testJobsToleratesTyposAndMatchesSynonyms(): void
    {
        $this->index->replaceJobs([
            new JobDocument('j1', 'Plombier', 'plombier', ['Chauffagiste sanitaire'], 'Plomberie', 'plomberie'),
            new JobDocument('j2', 'Électricien', 'electricien', [], 'Électricité', 'electricite'),
            new JobDocument('j3', 'Peintre', 'peintre', ['Décorateur'], 'Peinture', 'peinture'),
        ]);

        self::assertSame(['electricien'], self::slugs($this->index->suggestJobs('electricen', 8)), 'faute de frappe et accent');
        self::assertSame(['plombier'], self::slugs($this->index->suggestJobs('chauffagi', 8)), 'synonyme');
        self::assertSame(['peintre'], self::slugs($this->index->suggestJobs('decor', 8)));

        $this->index->updateJobs([new JobDocument('j4', 'Plaquiste', 'plaquiste', [], 'Plâtrerie', 'platrerie')], ['j1']);
        self::assertSame(['plaquiste'], self::slugs($this->index->suggestJobs('pla', 8)));
    }

    public function testReplacingSwapsContentAtomically(): void
    {
        $this->index->replaceJobs([new JobDocument('j1', 'Plombier', 'plombier', [], 'Plomberie', 'plomberie')]);
        $this->index->replaceJobs([new JobDocument('j2', 'Couvreur', 'couvreur', [], 'Toiture', 'toiture')]);

        self::assertSame([], $this->index->suggestJobs('plombier', 8));
        self::assertSame(['couvreur'], self::slugs($this->index->suggestJobs('couvr', 8)));
    }

    public function testPlacesRankByPopulationAndExpandSaintAbbreviation(): void
    {
        $this->index->replacePlaces([
            new PlaceDocument('76575', 'Saint-Étienne-du-Rouvray', 'saint-etienne-du-rouvray-76800', ['76800'], '76', 'Seine-Maritime', 28000),
            new PlaceDocument('42218', 'Saint-Étienne', 'saint-etienne-42000', ['42000', '42100'], '42', 'Loire', 171000),
            new PlaceDocument('14118', 'Caen', 'caen-14000', ['14000'], '14', 'Calvados', 109400),
        ]);

        $places = $this->index->suggestPlaces('st etienne', 8);

        self::assertSame(['42218', '76575'], array_map(static fn (PlaceDocument $p): string => $p->insee, $places));
        self::assertSame('Loire', $places[0]->departmentName);
        self::assertSame(171000, $places[0]->population);
        self::assertSame(['42000', '42100'], $places[0]->postalCodes);
    }
}
