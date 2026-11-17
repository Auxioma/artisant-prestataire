<?php

declare(strict_types=1);

namespace App\Content\Application\Contact;

use App\Shared\Application\Bus\Command;

/** P16 — message du formulaire de contact (captcha et limite d'envoi contrôlés en amont). */
final readonly class SubmitContactMessage implements Command
{
    public function __construct(
        public string $id,
        public string $reason,
        public string $name,
        public string $email,
        public ?string $phone,
        public ?string $requestReference,
        public string $message,
        public bool $consent,
        public ?string $attachmentPath = null,
        public ?string $attachmentName = null,
        public ?string $userId = null,
        public ?string $ipHash = null,
    ) {
    }
}
