<?php

declare(strict_types=1);

namespace App\Tests\Unit\Search;

use App\Search\Application\Port\SearchUnavailable;
use App\Search\Infrastructure\Meilisearch\MeilisearchAutocompleteIndex;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Toute erreur du moteur (réseau, clé refusée, tâche en échec) devient SearchUnavailable → repli PostgreSQL. */
final class MeilisearchAutocompleteIndexTest extends TestCase
{
    private static function adapter(MockHttpClient $search, ?MockHttpClient $admin = null): MeilisearchAutocompleteIndex
    {
        return new MeilisearchAutocompleteIndex($search, $admin ?? new MockHttpClient([]), 'tm_t_');
    }

    public function testSearchUsesTheReadOnlyClientAndMapsHits(): void
    {
        $search = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://meili.test/indexes/tm_t_jobs/search', $url);
            self::assertSame('{"q":"plomb","limit":8}', $options['body']);

            return new MockResponse((string) json_encode(['hits' => [['id' => 'j1', 'name' => 'Plombier', 'slug' => 'plombier', 'synonyms' => ['Chauffagiste', 42], 'categoryName' => 'Plomberie', 'categorySlug' => 'plomberie'], 'invalide']]));
        }, 'https://meili.test');

        $jobs = self::adapter($search)->suggestJobs('plomb', 8);

        self::assertCount(1, $jobs);
        self::assertSame('Plombier', $jobs[0]->name);
        self::assertSame(['Chauffagiste'], $jobs[0]->synonyms);
    }

    public function testNetworkFailureIsReportedAsUnavailable(): void
    {
        $search = new MockHttpClient(static fn (): MockResponse => throw new TransportException('Connection refused'), 'https://meili.test');

        $this->expectException(SearchUnavailable::class);
        self::adapter($search)->suggestPlaces('caen', 8);
    }

    public function testRejectedKeyIsReportedAsUnavailable(): void
    {
        $search = new MockHttpClient(new MockResponse('{"message":"The provided API key is invalid."}', ['http_code' => 403]), 'https://meili.test');

        $this->expectException(SearchUnavailable::class);
        self::adapter($search)->suggestJobs('plomb', 8);
    }

    public function testFailedIndexingTaskIsReported(): void
    {
        $admin = new MockHttpClient([
            new MockResponse('{"taskUid":7}', ['http_code' => 202]),
            new MockResponse('{"uid":7,"status":"failed","error":{"code":"invalid_document_id"}}'),
        ], 'https://meili.test');

        $this->expectException(SearchUnavailable::class);
        $this->expectExceptionMessage('invalid_document_id');
        self::adapter(new MockHttpClient([]), $admin)->updateJobs([], ['j1']);
    }
}
