<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Contact;

use App\Content\Application\Contact\ContactMessage;
use App\Content\Application\Port\ContactMailer;
use App\Notification\Application\Notification;
use App\Notification\Application\Notifier;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class NotificationContactMailer implements ContactMailer
{
    private const int EXCERPT = 300;

    public function __construct(
        private Notifier $notifier,
        #[Autowire('%env(SUPPORT_INBOX)%')]
        private string $supportInbox,
    ) {
    }

    public function acknowledge(ContactMessage $message, int $replyHours): void
    {
        $this->notifier->notify(new Notification('E-90', $message->email, $message->userId, [
            'nom' => $message->name,
            'motif' => $message->reason,
            'reference' => $message->reference,
            'delai_heures' => $replyHours,
            'extrait' => mb_substr($message->message, 0, self::EXCERPT),
        ]));
    }

    public function alertSupport(ContactMessage $message): void
    {
        $this->notifier->notify(new Notification('I-03', $this->supportInbox, null, [
            'reference' => $message->reference,
            'motif' => $message->reason,
            'nom' => $message->name,
            'email' => $message->email,
            'telephone' => $message->phone,
            'numero_demande' => $message->requestReference,
            'message' => $message->message,
            'piece_jointe' => null !== $message->attachmentFileId,
            'compte' => null !== $message->userId,
        ]));
    }
}
