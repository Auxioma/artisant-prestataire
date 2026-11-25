<?php

declare(strict_types=1);

namespace App\Search\Infrastructure\Meilisearch;

use App\Search\Application\Port\AutocompleteIndex;
use App\Search\Application\Port\JobDocument;
use App\Search\Application\Port\PlaceDocument;
use App\Search\Application\Port\SearchUnavailable;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Adaptateur Meilisearch (API REST). Deux clés : recherche (lecture, utilisée à chaque frappe) et administration
 * (indexation). Aucune clé n'est exposée au navigateur : le front interroge l'endpoint Symfony (ADR-0004).
 */
final readonly class MeilisearchAutocompleteIndex implements AutocompleteIndex
{
    private const int TASK_TIMEOUT_SECONDS = 120;
    private const int TASK_POLL_MICROSECONDS = 200_000;
    private const int BATCH = 10_000;

    public function __construct(
        #[Target('meilisearch_search.client')]
        private HttpClientInterface $searchClient,
        #[Target('meilisearch_admin.client')]
        private HttpClientInterface $adminClient,
        #[Autowire('%env(MEILISEARCH_INDEX_PREFIX)%')]
        private string $prefix,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function replaceJobs(array $jobs): void
    {
        $this->replace('jobs', 'id', [
            'searchableAttributes' => ['name', 'synonyms', 'categoryName'],
            'displayedAttributes' => ['id', 'name', 'slug', 'synonyms', 'categoryName', 'categorySlug'],
            'localizedAttributes' => [['attributePatterns' => ['*'], 'locales' => ['fra']]],
            'typoTolerance' => ['minWordSizeForTypos' => ['oneTypo' => 4, 'twoTypos' => 8]],
        ], array_map(self::jobPayload(...), $jobs));
    }

    public function replacePlaces(array $places): void
    {
        $this->replace('places', 'insee', [
            'searchableAttributes' => ['name', 'postalCodes', 'departmentName'],
            'displayedAttributes' => ['insee', 'name', 'slug', 'postalCodes', 'departmentCode', 'departmentName', 'population'],
            'sortableAttributes' => ['population'],
            'rankingRules' => ['words', 'typo', 'proximity', 'attribute', 'exactness', 'population:desc'],
            // Abréviations usuelles des toponymes (Saint-Étienne, Sainte-Maxime…).
            'synonyms' => ['st' => ['saint'], 'ste' => ['sainte'], 'saint' => ['st'], 'sainte' => ['ste']],
            'localizedAttributes' => [['attributePatterns' => ['*'], 'locales' => ['fra']]],
        ], array_map(self::placePayload(...), $places));
    }

    public function updateJobs(array $jobs, array $removedIds): void
    {
        $index = $this->prefix.'jobs';
        if ([] !== $jobs) {
            $this->wait($this->admin('PUT', "/indexes/$index/documents", array_map(self::jobPayload(...), $jobs)));
        }
        if ([] !== $removedIds) {
            $this->wait($this->admin('POST', "/indexes/$index/documents/delete-batch", $removedIds));
        }
    }

    public function suggestJobs(string $term, int $limit): array
    {
        $hits = $this->search('jobs', $term, $limit);

        return array_map(static fn (array $h): JobDocument => new JobDocument(
            self::str($h['id'] ?? null),
            self::str($h['name'] ?? null),
            self::str($h['slug'] ?? null),
            self::strList($h['synonyms'] ?? []),
            self::str($h['categoryName'] ?? null),
            self::str($h['categorySlug'] ?? null),
        ), $hits);
    }

    public function suggestPlaces(string $term, int $limit): array
    {
        $hits = $this->search('places', $term, $limit);

        return array_map(static fn (array $h): PlaceDocument => new PlaceDocument(
            self::str($h['insee'] ?? null),
            self::str($h['name'] ?? null),
            self::str($h['slug'] ?? null),
            self::strList($h['postalCodes'] ?? []),
            self::str($h['departmentCode'] ?? null),
            self::str($h['departmentName'] ?? null),
            \is_int($h['population'] ?? null) ? $h['population'] : 0,
        ), $hits);
    }

    /**
     * Index temporaire alimenté puis échangé atomiquement (pas d'index vide ou partiel visible pendant la réindexation).
     *
     * @param array<string, mixed>       $settings
     * @param list<array<string, mixed>> $documents
     */
    private function replace(string $name, string $primaryKey, array $settings, array $documents): void
    {
        $live = $this->prefix.$name;
        $staging = $live.'_staging';
        if ($this->indexExists($staging)) {
            $this->wait($this->admin('DELETE', "/indexes/$staging"));
        }
        $this->wait($this->admin('POST', '/indexes', ['uid' => $staging, 'primaryKey' => $primaryKey]));
        $this->wait($this->admin('PATCH', "/indexes/$staging/settings", $settings));
        foreach (array_chunk($documents, self::BATCH) as $chunk) {
            $this->wait($this->admin('POST', "/indexes/$staging/documents", $chunk));
        }
        if (!$this->indexExists($live)) {
            $this->wait($this->admin('POST', '/indexes', ['uid' => $live, 'primaryKey' => $primaryKey]));
        }
        $this->wait($this->admin('POST', '/swap-indexes', [['indexes' => [$live, $staging]]]));
        $this->wait($this->admin('DELETE', "/indexes/$staging"));
    }

    /**
     * @return list<array<mixed>>
     */
    private function search(string $name, string $term, int $limit): array
    {
        try {
            $response = $this->searchClient->request('POST', '/indexes/'.$this->prefix.$name.'/search', ['json' => ['q' => $term, 'limit' => $limit]]);
            $data = $response->toArray();
        } catch (ExceptionInterface $e) {
            // Aucune saisie utilisateur dans le journal (cadrage §5.9) : seuls l'index et la cause technique.
            $this->logger->warning('Moteur de recherche indisponible, repli PostgreSQL.', ['index' => $name, 'error' => $e->getMessage()]);
            throw new SearchUnavailable($e->getMessage(), 0, $e);
        }
        $hits = $data['hits'] ?? [];

        return \is_array($hits) ? array_values(array_filter($hits, \is_array(...))) : [];
    }

    private function indexExists(string $uid): bool
    {
        try {
            $status = $this->adminClient->request('GET', '/indexes/'.$uid)->getStatusCode();
        } catch (ExceptionInterface $e) {
            throw new SearchUnavailable($e->getMessage(), 0, $e);
        }
        if (404 === $status) {
            return false;
        }
        if (200 !== $status) {
            throw new SearchUnavailable(\sprintf('Réponse Meilisearch inattendue (%d) pour l’index %s', $status, $uid));
        }

        return true;
    }

    private function admin(string $method, string $path, mixed $body = null): ?int
    {
        try {
            $data = $this->adminClient->request($method, $path, null === $body ? [] : ['json' => $body])->toArray();
        } catch (ExceptionInterface $e) {
            throw new SearchUnavailable($e->getMessage(), 0, $e);
        }

        return \is_int($data['taskUid'] ?? null) ? $data['taskUid'] : null;
    }

    private function wait(?int $taskUid): void
    {
        if (null === $taskUid) {
            return;
        }
        $deadline = microtime(true) + self::TASK_TIMEOUT_SECONDS;
        do {
            try {
                $task = $this->adminClient->request('GET', '/tasks/'.$taskUid)->toArray();
            } catch (ExceptionInterface $e) {
                throw new SearchUnavailable($e->getMessage(), 0, $e);
            }
            $status = $task['status'] ?? null;
            if ('succeeded' === $status) {
                return;
            }
            if ('failed' === $status || 'canceled' === $status) {
                $error = \is_array($task['error'] ?? null) ? json_encode($task['error'], \JSON_THROW_ON_ERROR) : 'inconnue';
                throw new SearchUnavailable(\sprintf('Tâche Meilisearch %d en échec : %s', $taskUid, $error));
            }
            usleep(self::TASK_POLL_MICROSECONDS);
        } while (microtime(true) < $deadline);

        throw new SearchUnavailable(\sprintf('Tâche Meilisearch %d non terminée après %d s', $taskUid, self::TASK_TIMEOUT_SECONDS));
    }

    /** @return array<string, mixed> */
    private static function jobPayload(JobDocument $job): array
    {
        return ['id' => $job->id, 'name' => $job->name, 'slug' => $job->slug, 'synonyms' => $job->synonyms, 'categoryName' => $job->categoryName, 'categorySlug' => $job->categorySlug];
    }

    /** @return array<string, mixed> */
    private static function placePayload(PlaceDocument $place): array
    {
        return ['insee' => $place->insee, 'name' => $place->name, 'slug' => $place->slug, 'postalCodes' => $place->postalCodes, 'departmentCode' => $place->departmentCode, 'departmentName' => $place->departmentName, 'population' => $place->population];
    }

    private static function str(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }

    /** @return list<string> */
    private static function strList(mixed $value): array
    {
        return \is_array($value) ? array_values(array_filter($value, \is_string(...))) : [];
    }
}
