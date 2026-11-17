<?php

declare(strict_types=1);

namespace App\Identity\Application\EventHandler;

use App\Identity\Application\Port\IdentityMailer;
use App\Identity\Application\Port\LegalDocuments;
use App\Identity\Application\Port\LegalUpdateRecipients;
use App\Identity\Domain\Legal\Event\LegalDocumentPublished;
use App\Identity\Domain\Legal\LegalDocumentType;
use App\Identity\Domain\User\UserKind;
use App\Shared\Application\Bus\EventHandler;
use App\Shared\Domain\ValueObject\Email;

/**
 * E-95 — chaque nouvelle version des CGU / CGV est annoncée aux comptes actifs concernés (CGU : tous ; CGV : clients ;
 * CGV professionnelles : artisans). Traitement asynchrone, par lots.
 */
final readonly class NotifyLegalUpdate implements EventHandler
{
    private const int BATCH = 200;

    public function __construct(private LegalDocuments $documents, private LegalUpdateRecipients $recipients, private IdentityMailer $mailer)
    {
    }

    public function __invoke(LegalDocumentPublished $event): void
    {
        $type = LegalDocumentType::tryFrom($event->type);
        $document = $this->documents->get($event->documentId);
        if (null === $type || null === $document || !$type->requiresReacceptance()) {
            return;
        }
        $kind = match ($type) {
            LegalDocumentType::TermsOfSale => UserKind::Client,
            LegalDocumentType::ProTermsOfSale => UserKind::Artisan,
            default => null,
        };
        $after = null;
        do {
            $batch = $this->recipients->batch($kind, $after, self::BATCH);
            foreach ($batch as $recipient) {
                $this->mailer->sendLegalUpdate(new Email($recipient['email']), $recipient['first_name'], $recipient['id'], $document);
                $after = $recipient['id'];
            }
        } while (self::BATCH === \count($batch));
    }
}
