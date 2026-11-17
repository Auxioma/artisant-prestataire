<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Admin;

use App\ArtisanProfile\Application\PublicProfile\PublicArtisanProfile;
use App\ArtisanProfile\Application\PublicProfile\PublicProfileView;
use App\Catalog\Application\Questionnaire\QuestionnaireStore;
use App\QuoteRequest\Application\Risk\RequestRiskSignal;
use App\QuoteRequest\Domain\Request\QuoteRequestId;
use App\QuoteRequest\Domain\Request\QuoteRequestRepository;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Application\Identity\UserContact;
use App\Shared\Application\Identity\UserContactDetails;

/** BO04.1 — fiche : contenu, réponses lisibles (version du questionnaire utilisée), signaux de risque, client. */
final readonly class AdminRequestDetailHandler implements QueryHandler
{
    public function __construct(private QuoteRequestRepository $requests, private AdminRequestRows $rows, private QueryBus $queries, private QuestionnaireStore $questionnaires)
    {
    }

    public function __invoke(AdminRequestDetail $query): ?AdminRequestView
    {
        $row = $this->rows->row($query->requestId);
        $request = null === $row ? null : $this->requests->find(new QuoteRequestId($query->requestId));
        if (null === $row || null === $request) {
            return null;
        }
        $details = $request->details();
        $version = $request->questionnaireVersion();
        $questionnaire = null === $version ? null : $this->questionnaires->version($request->jobId(), $version);
        $contact = $this->queries->ask(new UserContact($request->clientId()));
        $target = null === $request->targetArtisanId() ? null : $this->queries->ask(new PublicArtisanProfile($request->targetArtisanId()));

        return new AdminRequestView(
            $row,
            $request->clientId(),
            $contact instanceof UserContactDetails ? $contact->firstName.' · '.$contact->email : null,
            $details->title,
            $details->summary,
            $details->description,
            null === $questionnaire ? [] : $questionnaire->schema->describe($details->answers),
            $details->projectType->label(),
            $details->timing->label(),
            $target instanceof PublicProfileView ? $target->name : null,
            $this->rows->attachmentCount($query->requestId),
            array_values(array_map(static fn (string $s): string => RequestRiskSignal::tryFrom($s)?->label() ?? $s, $request->riskSignals())),
            $request->statusReason(),
        );
    }
}
