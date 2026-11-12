<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Draft;

use App\QuoteRequest\Application\Port\RequestContentStore;
use App\QuoteRequest\Application\Submission\SubmitQuoteRequest;
use App\QuoteRequest\Application\Submission\SubmitQuoteRequestHandler;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Storage\FileShredder;
use App\Shared\Domain\Time\Clock;

/**
 * Envoi du brouillon : contrôles RG-065 et conditions figées par SubmitQuoteRequestHandler (même transaction), puis
 * reprise de l'adresse scellée et des pièces encore valables ; une pièce refusée par l'antivirus est détruite.
 */
final readonly class SubmitRequestDraftHandler implements CommandHandler
{
    private const string REJECTED = 'rejected';
    private const string SHRED_REASON = 'request_photo_rejected';

    public function __construct(
        private DraftStore $drafts,
        private SubmitQuoteRequestHandler $submit,
        private RequestContentStore $content,
        private FileShredder $shredder,
        private Clock $clock,
    ) {
    }

    public function __invoke(SubmitRequestDraft $command): void
    {
        $draft = $this->drafts->find(DraftOwner::user($command->userId)) ?? throw new DraftRejected('Votre brouillon a expiré : merci de recommencer votre demande.');
        $address = $draft->address;
        if ($draft->step < RequestDraftEditor::STEP_RECAP || null === $draft->jobId || null === $draft->projectType || null === $address || null === $draft->timing || null === $draft->contactPreference) {
            throw new DraftRejected('Votre demande est incomplète : vérifiez chaque étape.');
        }

        ($this->submit)(new SubmitQuoteRequest(
            $command->requestId,
            $command->userId,
            $draft->jobId,
            $address['city_insee'],
            $address['postal_code'],
            $address['label'],
            $draft->projectType,
            $draft->description,
            $draft->answers,
            $draft->budgetRange,
            $draft->timing,
            $draft->desiredDate,
            $draft->targetArtisanId,
            null !== $draft->targetArtisanId && null !== $command->broadcastToOthers ? $command->broadcastToOthers : $draft->broadcastToOthers,
            $command->ipHash,
            $draft->preferredSlots,
            $draft->occupantStatus,
            $draft->contactPreference,
            $draft->questionnaireVersion,
            $draft->title,
            $draft->summary,
            $draft->workNature,
        ));

        $this->content->saveAddress($command->requestId, $address['label'], $address['lat'], $address['lon'], $this->clock->now());
        $kept = [];
        foreach ($draft->attachments as $attachment) {
            if (self::REJECTED === $attachment->status) {
                $this->shredder->shred($attachment->fileId, self::SHRED_REASON);
            } else {
                $kept[] = ['file_id' => $attachment->fileId, 'name' => $attachment->name];
            }
        }
        $this->drafts->delete($draft->id);
        $this->content->attachFiles($command->requestId, $kept);
    }
}
