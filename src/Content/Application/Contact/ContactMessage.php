<?php

declare(strict_types=1);

namespace App\Content\Application\Contact;

final readonly class ContactMessage
{
    public function __construct(
        public string $id,
        public string $reference,
        public string $reason,
        public string $name,
        public string $email,
        public ?string $phone,
        public ?string $requestReference,
        public string $message,
        public ?string $attachmentFileId,
        public ?string $userId,
        public ?string $ipHash,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
