<?php

declare(strict_types=1);

namespace App\Messaging\Infrastructure\Persistence;

use App\Messaging\Application\Port\MessagingDirectory;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

/** Lectures SQL des schémas `request`, `artisan` et `quote` nécessaires à la messagerie. */
final readonly class DbalMessagingDirectory implements MessagingDirectory
{
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    private const string ARTISAN = "SELECT id, user_id, coalesce(trade_name, company_name, 'Professionnel') AS name, (status = 'verified' AND hidden_at IS NULL) AS public FROM artisan.artisan_profile";

    public function __construct(private Connection $connection)
    {
    }

    public function request(string $requestId): ?array
    {
        if (1 !== preg_match(self::UUID, $requestId)) {
            return null;
        }
        $r = $this->connection->fetchAssociative('SELECT r.client_id, r.number, r.title, coalesce(c.name, r.postal_code) AS city FROM request.quote_request r LEFT JOIN catalog.commune c ON c.insee = r.city_insee WHERE r.id = :id', ['id' => $requestId]);

        return false === $r ? null : ['client_user_id' => DbalValue::string($r['client_id']), 'number' => DbalValue::string($r['number']), 'title' => DbalValue::string($r['title']), 'city' => DbalValue::string($r['city'])];
    }

    public function artisanOfUser(string $userId): ?array
    {
        return 1 === preg_match(self::UUID, $userId) ? $this->artisanRow(self::ARTISAN.' WHERE user_id = :v', $userId) : null;
    }

    public function artisan(string $artisanId): ?array
    {
        return 1 === preg_match(self::UUID, $artisanId) ? $this->artisanRow(self::ARTISAN.' WHERE id = :v', $artisanId) : null;
    }

    public function hasSentQuote(string $requestId, string $artisanId): bool
    {
        return false !== $this->connection->fetchOne("SELECT 1 FROM quote.quote WHERE request_id = :r AND artisan_id = :a AND status <> 'draft'", ['r' => $requestId, 'a' => $artisanId]);
    }

    public function quoteAccepted(string $requestId, string $artisanId): bool
    {
        return false !== $this->connection->fetchOne("SELECT 1 FROM quote.quote WHERE request_id = :r AND artisan_id = :a AND status = 'accepted'", ['r' => $requestId, 'a' => $artisanId]);
    }

    public function preQuestionsSince(string $clientUserId, \DateTimeImmutable $since): int
    {
        return DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM messaging.conversation WHERE kind = 'pre_question' AND client_user_id = :c AND created_at >= :since", ['c' => $clientUserId, 'since' => $since->format(\DATE_ATOM)]));
    }

    /** @return array{artisan_id: string, user_id: string, name: string, public: bool}|null */
    private function artisanRow(string $sql, string $value): ?array
    {
        $r = $this->connection->fetchAssociative($sql, ['v' => $value]);

        return false === $r ? null : ['artisan_id' => DbalValue::string($r['id']), 'user_id' => DbalValue::string($r['user_id']), 'name' => DbalValue::string($r['name']), 'public' => true === $r['public']];
    }
}
