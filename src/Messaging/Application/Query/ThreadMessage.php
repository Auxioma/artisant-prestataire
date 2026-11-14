<?php

declare(strict_types=1);

namespace App\Messaging\Application\Query;

final readonly class ThreadMessage
{
    public function __construct(
        public string $id,
        public bool $mine,
        public string $body,
        public \DateTimeImmutable $at,
        public bool $masked,
        public bool $readByCounterpart,
        public bool $reportedByMe,
        public ?ThreadAttachment $attachment = null,
    ) {
    }
}
