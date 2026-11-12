<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Submission;

use App\Catalog\Application\Geo\Query\CommunesByInsee;
use App\Catalog\Application\Geo\Query\CommuneSummary;
use App\Catalog\Application\Query\JobIndexEntries;
use App\Catalog\Application\Query\JobIndexEntry;
use App\QuoteRequest\Application\Port\RequestRiskAssessor;
use App\QuoteRequest\Application\Port\RequestRiskInput;
use App\QuoteRequest\Domain\Request\ContactPreference;
use App\QuoteRequest\Domain\Request\DuplicateKey;
use App\QuoteRequest\Domain\Request\OccupantStatus;
use App\QuoteRequest\Domain\Request\PreferredSlot;
use App\QuoteRequest\Domain\Request\ProjectType;
use App\QuoteRequest\Domain\Request\QuoteRequest;
use App\QuoteRequest\Domain\Request\QuoteRequestId;
use App\QuoteRequest\Domain\Request\QuoteRequestRepository;
use App\QuoteRequest\Domain\Request\RequestDetails;
use App\QuoteRequest\Domain\Request\RequestRuleViolation;
use App\QuoteRequest\Domain\Request\SubmissionTerms;
use App\QuoteRequest\Domain\Request\Timing;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Bus\EventBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

/**
 * P11.7 — envoi d'une demande : métier et commune actifs, plafond de demandes ouvertes et anti-doublon (RG-065),
 * score de risque (RG-063), puis création avec les conditions figées (`quotes.max_per_request`, RG-064).
 */
final readonly class SubmitQuoteRequestHandler implements CommandHandler
{
    public function __construct(
        private QuoteRequestRepository $requests,
        private RequestRiskAssessor $risk,
        private SettingsProvider $settings,
        private QueryBus $queries,
        private EventBus $events,
        private AuditTrail $audit,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    public function __invoke(SubmitQuoteRequest $command): void
    {
        $now = $this->clock->now();
        $details = new RequestDetails(
            ProjectType::tryFrom($command->projectType) ?? throw new RequestRuleViolation('Choisissez un type de projet.'),
            $command->description,
            $command->answers,
            $command->budgetRange,
            Timing::tryFrom($command->timing) ?? throw new RequestRuleViolation('Choisissez un délai souhaité.'),
            self::date($command->desiredDate, $now),
            array_values(array_filter(array_map(PreferredSlot::tryFrom(...), $command->preferredSlots))),
            null === $command->occupantStatus ? null : (OccupantStatus::tryFrom($command->occupantStatus) ?? throw new RequestRuleViolation('Statut invalide.')),
        );
        $this->assertJobAvailable($command->jobId);
        $this->assertCommune($command->cityInsee, $command->postalCode);

        $this->requests->lockClient($command->clientId);
        if ($this->requests->countActiveOfClient($command->clientId) >= $this->settings->int(SettingKey::RequestsMaxOpenPerClient)) {
            throw new RequestRuleViolation(\sprintf('Vous avez déjà %d demandes en cours : clôturez-en une avant d’en déposer une nouvelle.', $this->settings->int(SettingKey::RequestsMaxOpenPerClient)));
        }
        $key = DuplicateKey::of($command->jobId, $command->address);
        $window = $this->settings->int(SettingKey::RequestsDuplicateWindowHours);
        if ($window > 0 && $this->requests->hasDuplicateSince($key, $now->modify(\sprintf('-%d hours', $window)))) {
            throw new RequestRuleViolation('Vous avez déjà envoyé une demande pour ce métier à cette adresse : retrouvez-la dans « Mes demandes ».');
        }

        $risk = $this->risk->assess(new RequestRiskInput($command->clientId, $command->jobId, $details->description, $details->answers, $command->ipHash, $now));
        $terms = new SubmissionTerms(
            $this->settings->int(SettingKey::QuotesMaxPerRequest),
            $this->settings->int(SettingKey::RequestsExpiryDays),
            $risk->score,
            $risk->signals,
            $this->settings->int(SettingKey::ModerationRequestRiskThreshold),
        );
        $request = QuoteRequest::submit(
            new QuoteRequestId($command->requestId),
            $this->requests->nextNumber($now),
            $command->clientId,
            $command->jobId,
            $command->targetArtisanId,
            $command->broadcastToOthers ?? $this->settings->bool(SettingKey::RequestsDirectBroadcastDefault),
            $command->cityInsee,
            $command->postalCode,
            $key,
            $details,
            $terms,
            $now,
            ContactPreference::tryFrom($command->contactPreference) ?? throw new RequestRuleViolation('Préférence de contact invalide.'),
            $command->questionnaireVersion,
        );
        $this->requests->add($request);
        $this->events->publish(...$request->releaseEvents());
        $this->audit->record(new AuditRecord(
            id: $this->ids->next(),
            occurredAt: $now,
            actorType: 'user',
            actorId: $command->clientId,
            action: 'request.submitted',
            subjectType: 'quote_request',
            subjectId: $command->requestId,
            context: ['number' => $request->number(), 'status' => $request->status()->value, 'max_quotes' => $request->maxQuotes(), 'risk_score' => $request->riskScore()],
        ));
    }

    private function assertJobAvailable(string $jobId): void
    {
        $entries = $this->queries->ask(new JobIndexEntries([$jobId]));
        foreach (\is_array($entries) ? $entries : [] as $entry) {
            if ($entry instanceof JobIndexEntry && $entry->id === $jobId && $entry->searchable) {
                return;
            }
        }

        throw new RequestRuleViolation('Ce métier n’est pas disponible.');
    }

    private function assertCommune(string $insee, string $postalCode): void
    {
        $communes = $this->queries->ask(new CommunesByInsee([$insee]));
        foreach (\is_array($communes) ? $communes : [] as $commune) {
            if ($commune instanceof CommuneSummary && $commune->insee === $insee && \in_array($postalCode, $commune->postalCodes, true)) {
                return;
            }
        }

        throw new RequestRuleViolation('Commune d’intervention inconnue ou code postal incohérent.');
    }

    /** « Date précise » : au format AAAA-MM-JJ, à partir d'aujourd'hui. */
    private static function date(?string $value, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value), $now->getTimezone());
        if (false === $date || $date->format('Y-m-d') !== trim($value) || $date < $now->setTime(0, 0)) {
            throw new RequestRuleViolation('Date souhaitée invalide ou passée.');
        }

        return $date;
    }
}
