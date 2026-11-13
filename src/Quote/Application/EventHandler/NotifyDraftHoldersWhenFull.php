<?php

declare(strict_types=1);

namespace App\Quote\Application\EventHandler;

use App\Quote\Application\Port\QuoteDraftHolders;
use App\Quote\Application\Port\QuoteMailer;
use App\QuoteRequest\Application\Pro\ProRequestAccess;
use App\QuoteRequest\Domain\Request\Event\QuoteRequestStatusChanged;
use App\QuoteRequest\Domain\Request\RequestStatus;
use App\Shared\Application\Bus\EventHandler;

/** E-33 — la demande est devenue complète : les artisans qui préparaient un devis sont prévenus (in-app). */
final readonly class NotifyDraftHoldersWhenFull implements EventHandler
{
    public function __construct(private QuoteDraftHolders $holders, private ProRequestAccess $access, private QuoteMailer $mailer)
    {
    }

    public function __invoke(QuoteRequestStatusChanged $event): void
    {
        if (RequestStatus::Full->value !== $event->to) {
            return;
        }
        foreach ($this->holders->userIdsWithDraftOn($event->requestId) as $userId) {
            $card = $this->access->card($userId, $event->requestId);
            if (null !== $card) {
                $this->mailer->notifyDraftHolder($userId, $event->requestId, $card->number, $card->jobName, $card->city);
            }
        }
    }
}
