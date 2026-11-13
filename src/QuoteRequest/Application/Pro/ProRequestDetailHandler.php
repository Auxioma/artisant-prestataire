<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Pro;

use App\Catalog\Application\Questionnaire\QuestionnaireStore;
use App\QuoteRequest\Application\Port\ProRequestReader;
use App\QuoteRequest\Application\Port\RequestAddenda;
use App\QuoteRequest\Domain\Request\PreferredSlot;
use App\QuoteRequest\Domain\Request\QuoteRequestId;
use App\QuoteRequest\Domain\Request\QuoteRequestRepository;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Application\Identity\UserContact;
use App\Shared\Application\Identity\UserContactDetails;

/** PR03.1 — contenu de la demande ; le client n'est désigné que par son prénom (RG-112), jamais son adresse. */
final readonly class ProRequestDetailHandler implements QueryHandler
{
    public function __construct(
        private ProRequestAccess $access,
        private ProRequestReader $reader,
        private QuoteRequestRepository $requests,
        private QuestionnaireStore $questionnaires,
        private QueryBus $queries,
        private RequestAddenda $addenda,
    ) {
    }

    public function __invoke(ProRequestDetail $query): ?ProRequestView
    {
        $card = $this->access->card($query->userId, $query->requestId);
        $request = null === $card ? null : $this->requests->find(new QuoteRequestId($query->requestId));
        if (null === $card || null === $request) {
            return null;
        }
        $details = $request->details();
        $version = $request->questionnaireVersion();
        $questionnaire = null === $version ? null : $this->questionnaires->version($request->jobId(), $version);
        $contact = $this->queries->ask(new UserContact($request->clientId()));

        return new ProRequestView(
            $card,
            $contact instanceof UserContactDetails ? $contact->firstName : null,
            $details->projectType->label(),
            $details->summary,
            $details->workNature,
            $details->description,
            null === $questionnaire ? [] : $questionnaire->schema->describe($details->answers),
            $this->reader->photos($query->requestId),
            $details->desiredDate,
            array_map(static fn (PreferredSlot $slot): string => $slot->label(), $details->preferredSlots),
            $details->occupantStatus?->label(),
            RequestState::Ignored === $card->state,
            $this->addenda->of($query->requestId),
        );
    }
}
