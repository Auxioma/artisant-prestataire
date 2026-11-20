<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Storage;

use App\Shared\Application\Storage\Port\StoredFileRepository;
use App\Shared\Application\Storage\StoredFile;
use App\Shared\Application\Storage\WrappedKey;
use App\Shared\Domain\Storage\FilePurpose;
use App\Shared\Domain\Storage\FileStatus;
use App\Shared\Domain\Storage\FileType;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class DbalStoredFileRepository implements StoredFileRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function add(StoredFile $file): void
    {
        $this->connection->insert('shared.stored_file', [
            'id' => $file->id,
            'purpose' => $file->purpose->value,
            'status' => $file->status->value,
            'mime' => $file->type->value,
            'size' => $file->size,
            'sha256' => $file->sha256,
            'original_name' => $file->originalName,
            'created_at' => $file->createdAt->format(\DATE_ATOM),
        ]);
    }

    public function find(string $id): ?StoredFile
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id)) {
            return null;
        }
        $row = $this->connection->fetchAssociative('SELECT * FROM shared.stored_file WHERE id = :id', ['id' => $id]);
        if (false === $row) {
            return null;
        }
        $kekId = null === $row['kek_id'] ? null : DbalValue::string($row['kek_id']);
        $wrapped = null === $row['wrapped_dek'] ? null : base64_decode(DbalValue::string($row['wrapped_dek']), true);

        return new StoredFile(
            DbalValue::string($row['id']),
            FilePurpose::from(DbalValue::string($row['purpose'])),
            FileStatus::from(DbalValue::string($row['status'])),
            FileType::from(DbalValue::string($row['mime'])),
            DbalValue::int($row['size']),
            DbalValue::string($row['sha256']),
            null === $row['original_name'] ? null : DbalValue::string($row['original_name']),
            new \DateTimeImmutable(DbalValue::string($row['created_at'])),
            null !== $kekId && \is_string($wrapped) ? new WrappedKey($kekId, $wrapped) : null,
        );
    }

    public function markAvailable(string $id, ?WrappedKey $key, \DateTimeImmutable $at): void
    {
        $this->transition($id, FileStatus::Available, [
            'kek_id' => $key?->kekId,
            'wrapped_dek' => null === $key ? null : base64_encode($key->wrappedDek),
            'scanned_at' => $at->format(\DATE_ATOM),
        ]);
    }

    public function markRejected(string $id, string $reason, \DateTimeImmutable $at): void
    {
        $this->transition($id, FileStatus::Rejected, ['rejected_reason' => $reason, 'scanned_at' => $at->format(\DATE_ATOM)]);
    }

    public function markShredded(string $id, \DateTimeImmutable $at): void
    {
        $this->connection->update('shared.stored_file', [
            'status' => FileStatus::Shredded->value,
            'kek_id' => null,
            'wrapped_dek' => null,
            'shredded_at' => $at->format(\DATE_ATOM),
        ], ['id' => $id]);
    }

    /** @param array<string, string|null> $fields */
    private function transition(string $id, FileStatus $to, array $fields): void
    {
        $updated = $this->connection->update('shared.stored_file', ['status' => $to->value] + $fields, [
            'id' => $id,
            'status' => FileStatus::Quarantined->value,
        ]);
        if (1 !== $updated) {
            throw new \LogicException(\sprintf('Le fichier %s n’est plus en quarantaine.', $id));
        }
    }
}
