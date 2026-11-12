<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Contact;

use App\Content\Application\Contact\ContactMessage;
use App\Content\Application\Port\ContactMessages;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class DbalContactMessages implements ContactMessages
{
    public function __construct(private Connection $connection)
    {
    }

    public function add(ContactMessage $m): void
    {
        $this->connection->insert('content.contact_message', [
            'id' => $m->id, 'reference' => $m->reference, 'reason' => $m->reason, 'name' => $m->name, 'email' => $m->email,
            'phone' => $m->phone, 'request_reference' => $m->requestReference, 'message' => $m->message,
            'attachment_file_id' => $m->attachmentFileId, 'user_id' => $m->userId, 'ip_hash' => $m->ipHash,
            'created_at' => $m->createdAt->format(\DATE_ATOM),
        ]);
    }

    public function find(string $id): ?ContactMessage
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id)) {
            return null;
        }
        $r = $this->connection->fetchAssociative('SELECT * FROM content.contact_message WHERE id = :id', ['id' => $id]);

        return false === $r ? null : new ContactMessage(
            DbalValue::string($r['id']),
            DbalValue::string($r['reference']),
            DbalValue::string($r['reason']),
            DbalValue::string($r['name']),
            DbalValue::string($r['email']),
            DbalValue::nullableString($r['phone']),
            DbalValue::nullableString($r['request_reference']),
            DbalValue::string($r['message']),
            DbalValue::nullableString($r['attachment_file_id']),
            DbalValue::nullableString($r['user_id']),
            DbalValue::nullableString($r['ip_hash']),
            new \DateTimeImmutable(DbalValue::string($r['created_at'])),
        );
    }
}
