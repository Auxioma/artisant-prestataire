<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Questionnaire;

use App\Catalog\Application\Questionnaire\JobQuestionnaireView;
use App\Catalog\Application\Questionnaire\QuestionnaireStore;
use App\Catalog\Domain\Questionnaire\QuestionnaireSchema;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class DbalQuestionnaireStore implements QuestionnaireStore
{
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(private Connection $connection, private IdGenerator $ids)
    {
    }

    public function activate(string $jobId, QuestionnaireSchema $schema, \DateTimeImmutable $now): int
    {
        $json = json_encode($schema->toArray(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        $current = $this->connection->fetchAssociative('SELECT version, schema = CAST(:schema AS jsonb) AS same FROM catalog.questionnaire_form WHERE job_id = :job AND active FOR UPDATE', ['job' => $jobId, 'schema' => $json]);
        if (false !== $current && true === $current['same']) {
            return DbalValue::int($current['version']);
        }
        $version = DbalValue::int($this->connection->fetchOne('SELECT coalesce(max(version), 0) + 1 FROM catalog.questionnaire_form WHERE job_id = :job', ['job' => $jobId]));
        $this->connection->executeStatement('UPDATE catalog.questionnaire_form SET active = false WHERE job_id = :job AND active', ['job' => $jobId]);
        $this->connection->insert('catalog.questionnaire_form', [
            'id' => $this->ids->next(), 'job_id' => $jobId, 'version' => $version, 'schema' => $json, 'active' => true, 'created_at' => $now->format(\DATE_ATOM),
        ], ['active' => 'boolean']);

        return $version;
    }

    public function active(string $jobId): ?JobQuestionnaireView
    {
        return $this->view($jobId, 'active');
    }

    public function version(string $jobId, int $version): ?JobQuestionnaireView
    {
        return $this->view($jobId, 'version = :version', ['version' => $version]);
    }

    /** @param array<string, int> $params */
    private function view(string $jobId, string $condition, array $params = []): ?JobQuestionnaireView
    {
        if (1 !== preg_match(self::UUID, $jobId)) {
            return null;
        }
        $row = $this->connection->fetchAssociative('SELECT version, schema FROM catalog.questionnaire_form WHERE job_id = :job AND '.$condition, ['job' => $jobId] + $params);
        if (false === $row) {
            return null;
        }
        $schema = DbalValue::json($row['schema']);

        return new JobQuestionnaireView($jobId, DbalValue::int($row['version']), QuestionnaireSchema::fromArray(\is_array($schema) ? $schema : []));
    }
}
