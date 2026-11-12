<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\EventHandler;

use App\QuoteRequest\Application\Draft\DraftRejected;
use App\QuoteRequest\Application\Draft\DraftStore;
use App\QuoteRequest\Application\Draft\SubmitRequestDraft;
use App\QuoteRequest\Domain\Request\RequestRuleViolation;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\EventHandler;
use App\Shared\Domain\Event\Integration\AccountEmailVerified;
use App\Shared\Domain\Identifier\IdGenerator;

/**
 * P11.7 / RG-040 — l'e-mail du compte est validé : la demande dont l'envoi attendait cette activation part
 * automatiquement (E-10 ensuite). Un refus (anti-doublon, plafond) laisse le brouillon au client, qui le retrouve dans
 * le tunnel avec le motif.
 */
final readonly class SubmitDraftOnEmailVerified implements EventHandler
{
    public function __construct(private DraftStore $drafts, private CommandBus $commands, private IdGenerator $ids)
    {
    }

    public function __invoke(AccountEmailVerified $event): void
    {
        $draftId = $this->drafts->claimPendingSubmission($event->userId);
        if (null === $draftId) {
            return;
        }
        try {
            $this->commands->dispatch(new SubmitRequestDraft($event->userId, $this->ids->next()));
        } catch (DraftRejected|RequestRuleViolation $e) {
            // Brouillon conservé : le client retrouve le récapitulatif et le motif en revenant dans le tunnel.
            $this->drafts->failSubmission($draftId, $e->getMessage());
        }
    }
}
