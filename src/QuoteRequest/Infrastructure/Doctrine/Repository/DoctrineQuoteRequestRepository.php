<?php

declare(strict_types=1);

namespace App\QuoteRequest\Infrastructure\Doctrine\Repository;

use App\QuoteRequest\Domain\Request\DuplicateKey;
use App\QuoteRequest\Domain\Request\QuoteRequest;
use App\QuoteRequest\Domain\Request\QuoteRequestId;
use App\QuoteRequest\Domain\Request\QuoteRequestRepository;
use App\QuoteRequest\Domain\Request\RequestStatus;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineQuoteRequestRepository implements QuoteRequestRepository
{
    private const string NUMBER_FORMAT = 'DM-%s-%06d';
    /** Espace de noms des verrous consultatifs « envoi de demande » (premier argument de pg_advisory_xact_lock). */
    private const int CLIENT_LOCK_SPACE = 4010;

    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function find(QuoteRequestId $id): ?QuoteRequest
    {
        return $this->em->find(QuoteRequest::class, $id);
    }

    public function findForUpdate(QuoteRequestId $id): ?QuoteRequest
    {
        return $this->em->find(QuoteRequest::class, $id, LockMode::PESSIMISTIC_WRITE);
    }

    public function add(QuoteRequest $request): void
    {
        $this->em->persist($request);
    }

    public function nextNumber(\DateTimeImmutable $now): string
    {
        return \sprintf(self::NUMBER_FORMAT, $now->format('Y'), DbalValue::int($this->em->getConnection()->fetchOne("SELECT nextval('request.quote_request_number_seq')")));
    }

    public function lockClient(string $clientId): void
    {
        $this->em->getConnection()->executeStatement('SELECT pg_advisory_xact_lock(:space, hashtext(:client))', ['space' => self::CLIENT_LOCK_SPACE, 'client' => $clientId]);
    }

    public function countActiveOfClient(string $clientId): int
    {
        return DbalValue::int($this->em->getConnection()->fetchOne(
            'SELECT count(*) FROM request.quote_request WHERE client_id = :client AND status IN (:statuses)',
            ['client' => $clientId, 'statuses' => array_map(static fn (RequestStatus $s): string => $s->value, RequestStatus::ACTIVE)],
            ['statuses' => ArrayParameterType::STRING],
        ));
    }

    public function hasDuplicateSince(DuplicateKey $key, \DateTimeImmutable $since): bool
    {
        return false !== $this->em->getConnection()->fetchOne(
            'SELECT 1 FROM request.quote_request WHERE duplicate_key = :key AND submitted_at >= :since AND status NOT IN (:excluded) LIMIT 1',
            ['key' => $key->value, 'since' => $since->format(\DATE_ATOM), 'excluded' => array_map(static fn (RequestStatus $s): string => $s->value, RequestStatus::NOT_DUPLICABLE)],
            ['excluded' => ArrayParameterType::STRING],
        );
    }
}
