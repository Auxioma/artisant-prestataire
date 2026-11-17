<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Client;

use App\QuoteRequest\Application\Port\RequestAddenda;
use App\QuoteRequest\Domain\Request\BudgetRange;
use App\QuoteRequest\Domain\Request\Event\QuoteRequestAddendumAdded;
use App\QuoteRequest\Domain\Request\OccupantStatus;
use App\QuoteRequest\Domain\Request\PreferredSlot;
use App\QuoteRequest\Domain\Request\QuoteRequest;
use App\QuoteRequest\Domain\Request\QuoteRequestId;
use App\QuoteRequest\Domain\Request\QuoteRequestRepository;
use App\QuoteRequest\Domain\Request\RequestDetails;
use App\QuoteRequest\Domain\Request\RequestRuleViolation;
use App\QuoteRequest\Domain\Request\RequestStatus;
use App\QuoteRequest\Domain\Request\Timing;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Bus\EventBus;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Text\ContactDetector;
use App\Shared\Domain\Time\Clock;

/**
 * C02 / RG-066 — « Modifier » une demande sans devis (le contenu repasse les contrôles de l'envoi : longueurs,
 * coordonnées interdites) ; « Ajouter un complément » à une demande active (nombre et longueur bornés).
 */
final readonly class EditQuoteRequestHandler implements CommandHandler
{
    public const int ADDENDUM_MIN = 10;
    public const int ADDENDUM_MAX = 1000;
    public const int ADDENDA_MAX = 5;
    private const int DESIRED_DATE_MAX_MONTHS = 12;

    public function __construct(private QuoteRequestRepository $requests, private RequestAddenda $addenda, private EventBus $events, private AuditTrail $audit, private IdGenerator $ids, private Clock $clock)
    {
    }

    public function __invoke(EditQuoteRequest|AddRequestAddendum $command): void
    {
        $now = $this->clock->now();
        $request = $this->requests->findForUpdate(new QuoteRequestId($command->requestId));
        if (null === $request || $request->clientId() !== $command->clientId) {
            throw new RequestRuleViolation('Demande introuvable.');
        }
        if ($command instanceof AddRequestAddendum) {
            $this->addendum($request, $command, $now);

            return;
        }
        $current = $request->details();
        $request->edit(new RequestDetails(
            $current->projectType,
            $command->description,
            $current->answers,
            null === BudgetRange::tryFrom($command->budgetRange ?? '') ? null : $command->budgetRange,
            Timing::tryFrom($command->timing) ?? throw new RequestRuleViolation('Choisissez un délai souhaité.'),
            self::date($command->desiredDate, $now),
            array_values(array_filter(array_map(PreferredSlot::tryFrom(...), $command->preferredSlots))),
            null === $command->occupantStatus || '' === $command->occupantStatus ? null : (OccupantStatus::tryFrom($command->occupantStatus) ?? throw new RequestRuleViolation('Statut invalide.')),
            $command->title,
            $command->summary,
            $current->workNature,
        ), $now);
        $this->events->publish(...$request->releaseEvents());
        $this->record($command->clientId, 'request.edited_by_client', $request, $now);
    }

    private function addendum(QuoteRequest $request, AddRequestAddendum $command, \DateTimeImmutable $now): void
    {
        if (!\in_array($request->status(), RequestStatus::ACTIVE, true)) {
            throw new RequestRuleViolation('Cette demande n’accepte plus de complément.');
        }
        $body = trim($command->body);
        if (mb_strlen($body) < self::ADDENDUM_MIN || mb_strlen($body) > self::ADDENDUM_MAX) {
            throw new RequestRuleViolation(\sprintf('Votre complément doit compter entre %d et %d caractères.', self::ADDENDUM_MIN, self::ADDENDUM_MAX));
        }
        if ([] !== ContactDetector::find($body)) {
            throw new RequestRuleViolation(RequestDetails::CONTACT_MESSAGE);
        }
        if ($this->addenda->count($command->requestId) >= self::ADDENDA_MAX) {
            throw new RequestRuleViolation(\sprintf('Vous avez déjà ajouté %d compléments à cette demande.', self::ADDENDA_MAX));
        }
        $this->addenda->add($command->addendumId, $command->requestId, $body, $now);
        $this->events->publish(new QuoteRequestAddendumAdded($command->requestId, $command->clientId, $command->addendumId, $now));
        $this->record($command->clientId, 'request.addendum_added', $request, $now);
    }

    private function record(string $clientId, string $action, QuoteRequest $request, \DateTimeImmutable $now): void
    {
        $this->audit->record(new AuditRecord(id: $this->ids->next(), occurredAt: $now, actorType: 'user', actorId: $clientId, action: $action, subjectType: 'quote_request', subjectId: $request->id()->value, context: ['number' => $request->number()]));
    }

    /** « Date précise » : AAAA-MM-JJ, d'aujourd'hui à 12 mois (P11). */
    private static function date(?string $value, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value), $now->getTimezone());
        if (false === $date || $date->format('Y-m-d') !== trim($value) || $date < $now->setTime(0, 0) || $date > $now->modify(\sprintf('+%d months', self::DESIRED_DATE_MAX_MONTHS))) {
            throw new RequestRuleViolation('Choisissez une date à venir.');
        }

        return $date;
    }
}
