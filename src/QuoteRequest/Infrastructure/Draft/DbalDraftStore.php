<?php

declare(strict_types=1);

namespace App\QuoteRequest\Infrastructure\Draft;

use App\QuoteRequest\Application\Draft\DraftAttachmentView;
use App\QuoteRequest\Application\Draft\DraftOwner;
use App\QuoteRequest\Application\Draft\DraftStore;
use App\QuoteRequest\Application\Draft\RequestDraftView;
use App\Shared\Application\Security\SecretBox;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class DbalDraftStore implements DraftStore
{
    private const string PDF = 'application/pdf';

    public function __construct(private Connection $connection, private SecretBox $box)
    {
    }

    public function find(DraftOwner $owner): ?RequestDraftView
    {
        [$column, $value] = self::owner($owner);
        $row = $this->connection->fetchAssociative('SELECT id, step, payload, updated_at, pending_user_id, submit_requested_at FROM request.request_draft WHERE '.$column.' = :owner AND expires_at > now()', ['owner' => $value]);
        if (false === $row) {
            return null;
        }
        $id = DbalValue::string($row['id']);
        $attachments = array_map(
            static fn (array $a): DraftAttachmentView => new DraftAttachmentView(DbalValue::string($a['file_id']), DbalValue::string($a['original_name']), DbalValue::string($a['status']), self::PDF !== $a['mime']),
            $this->connection->fetchAllAssociative('SELECT a.file_id, a.original_name, f.status, f.mime FROM request.draft_attachment a JOIN shared.stored_file f ON f.id = a.file_id WHERE a.draft_id = :id ORDER BY a.position', ['id' => $id]),
        );
        $payload = DbalValue::json($row['payload']);
        /** @var array<string, mixed> $payload */
        $payload = \is_array($payload) ? $payload : [];
        // RG-022bis : l'adresse est scellée au repos ; un scellé illisible (clé tournée, corruption) vaut absence d'adresse.
        if (\is_string($payload['address'] ?? null)) {
            try {
                $payload['address'] = json_decode($this->box->open($payload['address']), true, 4, \JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                $payload['address'] = null;
            }
        }

        $requested = DbalValue::nullableString($row['submit_requested_at']);

        return RequestDraftView::fromPayload($id, DbalValue::int($row['step']), $payload, $attachments, new \DateTimeImmutable(DbalValue::string($row['updated_at'])), DbalValue::nullableString($row['pending_user_id']), null === $requested ? null : new \DateTimeImmutable($requested));
    }

    public function save(DraftOwner $owner, RequestDraftView $draft, \DateTimeImmutable $expiresAt): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO request.request_draft (id, owner_user_id, owner_token_hash, step, payload, created_at, updated_at, expires_at)
                VALUES (:id, :user, :token, :step, :payload, :now, :now, :expires)
                ON CONFLICT (id) DO UPDATE SET step = EXCLUDED.step, payload = EXCLUDED.payload, updated_at = EXCLUDED.updated_at, expires_at = EXCLUDED.expires_at
                SQL,
            [
                'id' => $draft->id, 'user' => $owner->userId, 'token' => $owner->tokenHash, 'step' => $draft->step,
                'payload' => json_encode($this->sealed($draft->payload()), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
                'now' => $draft->updatedAt->format(\DATE_ATOM), 'expires' => $expiresAt->format(\DATE_ATOM),
            ],
        );
    }

    public function attach(string $draftId, string $fileId, string $name, \DateTimeImmutable $now): void
    {
        $this->connection->executeStatement(
            'INSERT INTO request.draft_attachment (draft_id, file_id, original_name, position, created_at) SELECT :draft, :file, :name, coalesce(max(position), 0) + 1, :now FROM request.draft_attachment WHERE draft_id = :draft',
            ['draft' => $draftId, 'file' => $fileId, 'name' => mb_substr($name, 0, 120), 'now' => $now->format(\DATE_ATOM)],
        );
    }

    public function detach(string $draftId, string $fileId): bool
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $fileId)) {
            return false;
        }

        return 1 === $this->connection->executeStatement('DELETE FROM request.draft_attachment WHERE draft_id = :draft AND file_id = :file', ['draft' => $draftId, 'file' => $fileId]);
    }

    public function markPendingAccount(string $draftId, string $userId): void
    {
        $this->connection->executeStatement('UPDATE request.request_draft SET pending_user_id = :u WHERE id = :id', ['u' => $userId, 'id' => $draftId]);
    }

    public function requestSubmission(string $draftId, \DateTimeImmutable $now): void
    {
        $this->connection->executeStatement('UPDATE request.request_draft SET submit_requested_at = :now WHERE id = :id', ['now' => $now->format(\DATE_ATOM), 'id' => $draftId]);
    }

    public function claimPendingSubmission(string $userId): ?string
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, owner_user_id FROM request.request_draft WHERE (owner_user_id = :u OR pending_user_id = :u) AND submit_requested_at IS NOT NULL AND expires_at > now() ORDER BY updated_at DESC LIMIT 1 FOR UPDATE',
            ['u' => $userId],
        );
        if (false === $row) {
            return null;
        }
        $id = DbalValue::string($row['id']);
        if (null === $row['owner_user_id']) {
            // Un compte tout juste activé n'a pas d'autre brouillon : celui du tunnel lui est rattaché.
            $this->connection->executeStatement('DELETE FROM request.request_draft WHERE owner_user_id = :u AND id <> :id', ['u' => $userId, 'id' => $id]);
            $this->connection->executeStatement('UPDATE request.request_draft SET owner_user_id = :u, owner_token_hash = NULL WHERE id = :id', ['u' => $userId, 'id' => $id]);
        }

        return $id;
    }

    public function delete(string $draftId): void
    {
        $this->connection->executeStatement('DELETE FROM request.draft_attachment WHERE draft_id = :id', ['id' => $draftId]);
        $this->connection->executeStatement('DELETE FROM request.request_draft WHERE id = :id', ['id' => $draftId]);
    }

    public function adopt(DraftOwner $visitor, string $userId): array
    {
        $visitorDraft = $this->connection->fetchAssociative('SELECT id, updated_at FROM request.request_draft WHERE owner_token_hash = :t AND expires_at > now() FOR UPDATE', ['t' => $visitor->tokenHash]);
        if (false === $visitorDraft) {
            return [];
        }
        $userDraft = $this->connection->fetchAssociative('SELECT id, updated_at FROM request.request_draft WHERE owner_user_id = :u FOR UPDATE', ['u' => $userId]);
        $keepUserDraft = false !== $userDraft && new \DateTimeImmutable(DbalValue::string($userDraft['updated_at'])) > new \DateTimeImmutable(DbalValue::string($visitorDraft['updated_at']));
        $loser = $keepUserDraft ? DbalValue::string($visitorDraft['id']) : (false === $userDraft ? null : DbalValue::string($userDraft['id']));
        $files = null === $loser ? [] : $this->files($loser);
        if (null !== $loser) {
            $this->delete($loser);
        }
        if (!$keepUserDraft) {
            $this->connection->executeStatement('UPDATE request.request_draft SET owner_user_id = :u, owner_token_hash = NULL WHERE id = :id', ['u' => $userId, 'id' => $visitorDraft['id']]);
        }

        return $files;
    }

    /** @return list<string> */
    private function files(string $draftId): array
    {
        return array_map(DbalValue::string(...), $this->connection->fetchFirstColumn('SELECT file_id FROM request.draft_attachment WHERE draft_id = :id', ['id' => $draftId]));
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function sealed(array $payload): array
    {
        if (null !== $payload['address']) {
            $payload['address'] = $this->box->seal(json_encode($payload['address'], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));
        }

        return $payload;
    }

    /** @return array{string, string} */
    private static function owner(DraftOwner $owner): array
    {
        return null !== $owner->userId ? ['owner_user_id', $owner->userId] : ['owner_token_hash', (string) $owner->tokenHash];
    }
}
